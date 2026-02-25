<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng workflow_step_approvals — theo dõi từng bản ghi phê duyệt.
 *
 * Mục đích:
 *   Mỗi row đại diện cho 1 "nhiệm vụ phê duyệt" được giao cho 1 người (hoặc 1 role).
 *   Đây là nơi approver thực hiện hành động: approve / reject.
 *
 * Số rows được tạo per step:
 *   Sequential / AnyOf:
 *     → 1 row, approver_id = NULL, approver_role = 'manager'
 *     → Bất kỳ user có role 'manager' trong org đều có thể claim và approve
 *     → Step complete khi có 1 approval row chuyển sang Approved
 *
 *   Parallel:
 *     → N rows, mỗi row có approver_id = UUID của 1 user cụ thể
 *     → N = số lượng users có role trong org tại thời điểm start()
 *     → Step complete khi TẤT CẢ N rows đều Approved
 *     → Nếu bất kỳ 1 row nào Rejected → instance rejected ngay
 *
 * Ví dụ dữ liệu:
 *   Workflow "purchase-order-approval", instance #42, step 1 (sequential, role='manager'):
 *   | id | instance_id | step_order | approver_id | approver_role | status  |
 *   |----|-------------|------------|-------------|---------------|---------|
 *   | 1  | #42         | 1          | NULL        | manager       | pending |
 *
 *   Sau khi manager A approve:
 *   | 1  | #42         | 1          | uuid-A      | manager       | approved |
 *   (approver_id được cập nhật khi approve)
 *
 *   Workflow "board-approval", instance #43, step 1 (parallel, role='board_member', 3 members):
 *   | id | instance_id | step_order | approver_id | status  |
 *   |----|-------------|------------|-------------|---------|
 *   | 2  | #43         | 1          | uuid-B1     | pending |
 *   | 3  | #43         | 1          | uuid-B2     | pending |
 *   | 4  | #43         | 1          | uuid-B3     | approved| ← B3 approve trước
 *   Step chưa complete (vẫn còn pending rows)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_step_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /**
             * workflow_instance_id — FK tới instance đang chạy.
             *
             * Cascade delete: khi xóa instance → xóa tất cả approval rows của nó.
             */
            $table->uuid('workflow_instance_id')->comment('FK → workflow_instances');
            $table->foreign('workflow_instance_id')
                ->references('id')
                ->on('workflow_instances')
                ->cascadeOnDelete();

            /**
             * workflow_definition_step_id — Tham chiếu tới step template.
             *
             * Nullable vì step template có thể bị xóa (history preservation).
             * Dùng để đọc step config (type, deadline_hours, escalate_to_role)
             * khi cần re-check mà không query lại definition.
             */
            $table->uuid('workflow_definition_step_id')->nullable()->comment('FK → workflow_definition_steps (nullable)');
            $table->foreign('workflow_definition_step_id')
                ->references('id')
                ->on('workflow_definition_steps')
                ->nullOnDelete();

            /**
             * step_order — Số thứ tự bước (denormalized từ definition step).
             *
             * Denormalize để query nhanh mà không cần JOIN với definition_steps.
             * VD: "Tìm tất cả approvals của step 2 trong instance #42"
             *   → WHERE workflow_instance_id = ? AND step_order = 2
             */
            $table->unsignedSmallInteger('step_order')->comment('Thứ tự bước (denormalized từ definition_step)');

            /**
             * approver_id — UUID của user được chỉ định approve row này.
             *
             * NULL = chưa chỉ định (sequential/any_of): ai cũng có thể approve
             * UUID = chỉ định cụ thể (parallel hoặc submitter chọn)
             *
             * Khi approve() được gọi với sequential row (approver_id = NULL):
             *   → approver_id sẽ được set = user đã gọi approve()
             *   → Để tracking ai thực sự đã approve
             */
            $table->uuid('approver_id')->nullable()->comment('UUID của approver (NULL = any user with role)');

            /**
             * approver_role — Role được phép approve row này.
             *
             * Denormalized từ WorkflowDefinitionStep.approver_role.
             * Dùng để:
             *   1. Validate quyền khi approve() được gọi
             *   2. Hiển thị "required role" trong UI
             *   3. Sau khi escalate, row mới có escalate_to_role ở đây
             */
            $table->string('approver_role', 100)->nullable()->comment('Role được phép approve (denormalized)');

            /**
             * status — Trạng thái hiện tại của approval này.
             *
             * pending   = chưa xử lý
             * approved  = đã phê duyệt
             * rejected  = đã từ chối
             * delegated = đã ủy quyền cho người khác (future)
             * expired   = quá deadline SLA, đã escalate
             *
             * Xem WorkflowStepApprovalStatus enum để biết transitions hợp lệ.
             */
            $table->string('status', 20)->default('pending')->comment('pending | approved | rejected | delegated | expired');

            /**
             * comment — Nhận xét của approver khi approve hoặc reject.
             *
             * Bắt buộc khi reject (enforced ở application layer, không DB constraint).
             * Tùy chọn khi approve.
             * Hiển thị trong workflow history để submitter biết lý do.
             */
            $table->text('comment')->nullable()->comment('Nhận xét của approver (bắt buộc khi reject)');

            /**
             * decided_at — Thời điểm approver đưa ra quyết định.
             *
             * Set khi: approve(), reject(), delegate()
             * NULL nếu chưa quyết định (status = pending hoặc expired)
             */
            $table->timestamp('decided_at')->nullable()->comment('Thời điểm quyết định (approve/reject)');

            /**
             * deadline_at — Deadline cụ thể cho row này.
             *
             * Tính từ: thời điểm row được tạo + deadline_hours từ definition step
             * NULL = không có deadline
             *
             * WorkflowEngine.escalateExpired() query:
             *   WHERE status = 'pending' AND deadline_at < NOW()
             */
            $table->timestamp('deadline_at')->nullable()->comment('Deadline cụ thể (created_at + deadline_hours)');

            /**
             * notified_at — Thời điểm đã gửi notification cho approver.
             *
             * Dùng để:
             *   1. Tránh gửi notification trùng lặp
             *   2. Tracking SLA từ thời điểm approver nhận được
             *   3. Gửi reminder sau X giờ nếu chưa xử lý
             */
            $table->timestamp('notified_at')->nullable()->comment('Thời điểm đã notify approver');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Index để tìm nhanh approvals của 1 instance (dùng nhiều nhất)
            $table->index(['workflow_instance_id', 'step_order'], 'wf_step_approvals_instance_step_idx');

            // Index để tìm approvals của 1 user (trang "việc cần làm của tôi")
            $table->index('approver_id');

            // Index để tìm pending approvals quá hạn (cho escalation command)
            $table->index(['status', 'deadline_at'], 'wf_step_approvals_escalation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_approvals');
    }
};

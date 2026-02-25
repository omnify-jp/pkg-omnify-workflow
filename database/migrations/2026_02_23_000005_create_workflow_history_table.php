<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng workflow_history — audit trail bất biến của workflow.
 *
 * Mục đích:
 *   Lưu lại toàn bộ các sự kiện đã xảy ra trong vòng đời của 1 workflow instance.
 *   Bảng này chỉ INSERT, không bao giờ UPDATE hay DELETE (append-only).
 *
 * Tính chất:
 *   1. Immutable  — Mỗi row là 1 sự kiện bất biến, không sửa đổi
 *   2. Complete   — Ghi lại TẤT CẢ hành động (kể cả system actions)
 *   3. Auditable  — Có thể reconstruct toàn bộ workflow từ history
 *   4. Ordered    — created_at là thứ tự thời gian thực tế
 *
 * Actions được ghi lại:
 *   submitted     → User submit workflow
 *   step_started  → Bước mới được kích hoạt
 *   step_approved → 1 approver đã approve (trong parallel: ghi per-approver)
 *   step_completed→ Toàn bộ step hoàn thành (advance sang bước tiếp)
 *   approved      → Workflow hoàn tất (all steps done)
 *   rejected      → Approver reject (kéo theo instance bị rejected)
 *   cancelled     → Submitter/admin hủy
 *   escalated     → Quá deadline, chuyển escalate_to_role
 *   expired       → Step approval hết hạn SLA
 *
 * Ví dụ history của 1 workflow:
 *   | created_at  | actor_id | action        | from    | to      | comment          |
 *   |-------------|----------|---------------|---------|---------|------------------|
 *   | 09:00       | userA    | submitted     | NULL    | pending |                  |
 *   | 09:00       | system   | step_started  | NULL    | pending | Step 1: Manager  |
 *   | 10:30       | userB    | step_approved | pending | pending | Trông OK         |
 *   | 10:30       | system   | step_completed| pending | pending | Advance to step 2|
 *   | 10:30       | system   | step_started  | pending | pending | Step 2: Director |
 *   | 11:00       | userC    | approved      | pending | approved| Đồng ý           |
 *
 * Sử dụng:
 *   $instance->history → Collection<WorkflowHistory> (ordered by created_at asc)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_history', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /**
             * workflow_instance_id — FK tới instance liên quan.
             *
             * Cascade delete: khi xóa instance → xóa luôn history.
             * (History không có ý nghĩa nếu không có instance)
             */
            $table->uuid('workflow_instance_id')->comment('FK → workflow_instances');
            $table->foreign('workflow_instance_id')
                ->references('id')
                ->on('workflow_instances')
                ->cascadeOnDelete();

            /**
             * actor_id — UUID của user thực hiện hành động.
             *
             * NULL = system action (VD: escalation, auto-approve on timeout)
             * UUID = user action (approve, reject, cancel, submit)
             *
             * Không FK cứng vì user model ở package khác (cross-package).
             */
            $table->uuid('actor_id')->nullable()->comment('UUID của user (NULL = system action)');

            /**
             * action — Tên hành động đã xảy ra.
             *
             * Các giá trị chuẩn:
             *   submitted      → Workflow được submit
             *   step_started   → Step mới được kích hoạt
             *   step_approved  → 1 approver approve trong bước (dùng với parallel)
             *   step_completed → Toàn bộ bước hoàn thành
             *   approved       → Workflow hoàn tất thành công
             *   rejected       → Workflow bị từ chối
             *   cancelled      → Workflow bị hủy
             *   escalated      → Step được escalate do quá deadline
             *   expired        → 1 approval row hết hạn
             *
             * Có thể thêm custom actions nếu cần theo dõi thêm events.
             */
            $table->string('action', 50)->comment('Loại hành động: submitted | step_started | approved | rejected | cancelled | escalated | ...');

            /**
             * from_status / to_status — Chuyển trạng thái của workflow instance.
             *
             * Không phải lúc nào cũng có giá trị:
             *   step_started: from = NULL (không đổi status instance)
             *   step_approved: from = pending, to = pending (không đổi status instance)
             *   approved: from = pending, to = approved
             *
             * Lưu status của INSTANCE, không phải của step approval.
             */
            $table->string('from_status', 20)->nullable()->comment('Trạng thái instance trước khi hành động');
            $table->string('to_status', 20)->nullable()->comment('Trạng thái instance sau khi hành động');

            /**
             * step_order — Bước đang được xử lý tại thời điểm action.
             *
             * Dùng để filter history theo bước khi hiển thị UI.
             * NULL cho các actions ở level instance (submitted, approved, cancelled).
             */
            $table->unsignedSmallInteger('step_order')->nullable()->comment('Bước đang xử lý tại thời điểm action');

            /**
             * comment — Nhận xét được ghi lại cùng với action.
             *
             * Nguồn:
             *   - Approver điền khi approve/reject
             *   - System message khi escalate ("Escalated from manager to admin")
             *   - Submitter điền khi cancel
             */
            $table->text('comment')->nullable()->comment('Nhận xét / lý do đi kèm action');

            /**
             * metadata — JSON data bổ sung theo action.
             *
             * VD khi step_approved:
             *   { "approval_id": "uuid", "approver_role": "manager" }
             *
             * VD khi escalated:
             *   { "from_role": "manager", "to_role": "admin", "deadline_at": "..." }
             *
             * Giúp debug và audit mà không cần query bảng khác.
             */
            $table->json('metadata')->nullable()->comment('JSON context bổ sung (debug/audit)');

            /**
             * created_at — Thời điểm xảy ra sự kiện.
             *
             * Đây là thứ tự thời gian thực tế. Khi hiển thị history:
             *   ORDER BY workflow_history.created_at ASC
             *
             * Không có updated_at vì rows là immutable.
             */
            $table->timestamp('created_at')->nullable()->comment('Thời điểm xảy ra sự kiện (immutable)');

            // Index chính: lấy history của 1 instance theo thứ tự thời gian
            $table->index(['workflow_instance_id', 'created_at'], 'wf_history_instance_time_idx');

            // Index để tìm tất cả actions của 1 user (audit user)
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_history');
    }
};

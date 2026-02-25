<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng workflow_instances — mỗi lần chạy thực tế của một workflow.
 *
 * Mục đích:
 *   WorkflowInstance là "phiên bản đang chạy" của một WorkflowDefinition.
 *   Khi user submit đơn nghỉ phép → tạo 1 WorkflowInstance gắn với LeaveRequest đó.
 *
 * Morph Relationship (Polymorphic):
 *   workflowable_type + workflowable_id = polymorphic FK tới bất kỳ model nào.
 *
 *   Ví dụ:
 *     workflowable_type = 'App\Models\LeaveRequest'  workflowable_id = 'uuid-of-leave-request'
 *     workflowable_type = 'App\Models\PurchaseOrder' workflowable_id = 'uuid-of-purchase-order'
 *
 *   Để dùng, model cần implement HasWorkflow trait:
 *   ```php
 *   class LeaveRequest extends Model {
 *       use HasWorkflow;
 *   }
 *   $leaveRequest->startWorkflow('leave-approval', auth()->user());
 *   ```
 *
 * Vòng đời:
 *   1. startWorkflow() → tạo instance với status = Pending, current_step = 1
 *   2. Tạo WorkflowStepApprovals cho step 1
 *   3. Approver approve → nếu step hoàn thành → current_step++ hoặc status = Approved
 *   4. Approver reject → status = Rejected (ngay lập tức)
 *   5. Submitter/admin cancel → status = Cancelled
 *
 * Giới hạn:
 *   - 1 model chỉ nên có 1 active workflow tại 1 thời điểm
 *   - HasWorkflow.startWorkflow() nên kiểm tra trước khi tạo mới
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /**
             * workflow_definition_id — Tham chiếu tới template.
             *
             * KHÔNG cascade delete để giữ lại lịch sử ngay cả khi definition bị xóa.
             * Set nullable → orphaned instances vẫn readable (historical data).
             *
             * Lưu ý: Khi definition bị sửa (thêm/bỏ steps), các instances đang chạy
             * vẫn theo cấu trúc cũ (snapshot tại thời điểm start). Điều này là đúng.
             */
            $table->uuid('workflow_definition_id')->nullable()->comment('FK → workflow_definitions (nullable để giữ history khi definition bị xóa)');
            $table->foreign('workflow_definition_id')
                ->references('id')
                ->on('workflow_definitions')
                ->nullOnDelete();

            /**
             * workflowable_type + workflowable_id — Polymorphic morph.
             *
             * workflowable_type: Full class name của model chủ
             *   VD: 'App\Models\LeaveRequest'
             *   Nên đăng ký morph map để tránh class name thay đổi:
             *   Relation::morphMap(['leave_request' => LeaveRequest::class]);
             *
             * workflowable_id: UUID của record chủ
             *   Dùng string(36) để compatible với cả UUID và ULID
             */
            $table->string('workflowable_type', 200)->comment('Morph type: class name của model');
            $table->string('workflowable_id', 36)->comment('Morph ID: UUID/ULID của record model');

            /**
             * status — Trạng thái tổng thể của workflow instance.
             *
             * Xem WorkflowStatus enum để biết các chuyển trạng thái hợp lệ.
             * pending   = đang chờ xử lý (active)
             * approved  = đã phê duyệt (terminal)
             * rejected  = đã từ chối (terminal)
             * cancelled = đã hủy (terminal)
             */
            $table->string('status', 20)->default('pending')->comment('pending | approved | rejected | cancelled');

            /**
             * current_step — Step đang xử lý hiện tại.
             *
             * Bắt đầu từ 1 (step đầu tiên).
             * Khi 1 step hoàn thành: current_step++
             * Khi current_step > max_step: status = approved
             *
             * Dùng để query workflow_step_approvals:
             *   WHERE workflow_instance_id = ? AND step_order = current_step
             */
            $table->unsignedSmallInteger('current_step')->default(1)->comment('Bước đang xử lý hiện tại (bắt đầu từ 1)');

            /**
             * submitted_by — UUID của user đã submit.
             *
             * Không FK cứng vì user model ở package khác.
             * Application layer kiểm tra tính hợp lệ.
             *
             * Dùng cho:
             *   - Gửi notification khi approved/rejected
             *   - Hiển thị "submitted by" trong UI
             *   - Cho phép submitter hủy request
             */
            $table->uuid('submitted_by')->nullable()->comment('UUID của user submit (no FK — cross-package)');

            $table->timestamp('submitted_at')->nullable()->comment('Thời điểm submit (start workflow)');
            $table->timestamp('completed_at')->nullable()->comment('Thời điểm kết thúc (approved/rejected/cancelled)');

            /**
             * metadata — JSON data bổ sung từ application.
             *
             * Dùng để pass context từ application vào workflow:
             *   {
             *     "reason": "Việc gia đình",
             *     "leave_days": 3,
             *     "department": "Engineering",
             *     "urgency": "normal"
             *   }
             *
             * WorkflowEngine không xử lý metadata — chỉ lưu và expose qua events.
             * Host application listeners có thể đọc để customize behavior.
             */
            $table->json('metadata')->nullable()->comment('JSON context từ application (không xử lý bởi engine)');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Index để tìm kiếm instances của 1 model cụ thể (morph)
            $table->index(['workflowable_type', 'workflowable_id'], 'wf_instances_workflowable_index');

            // Index để lọc theo status (hiển thị pending approvals, etc.)
            $table->index('status');

            // Index để tìm các workflow submitted by 1 user
            $table->index('submitted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};

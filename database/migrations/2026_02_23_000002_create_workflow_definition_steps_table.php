<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng workflow_definition_steps — các bước của một workflow template.
 *
 * Mục đích:
 *   Mỗi WorkflowDefinition có 1 hoặc nhiều steps theo thứ tự (step_order).
 *   Steps xác định AI phê duyệt ở mỗi bước và quy tắc (sequential/parallel).
 *
 * Ví dụ: Workflow "purchase-order-approval" có 3 steps:
 *   Step 1 (sequential): Quản lý trực tiếp (role: manager), deadline 24h
 *   Step 2 (sequential): Giám đốc tài chính (role: finance_director), deadline 48h
 *   Step 3 (parallel):   Tất cả thành viên HĐ (role: board_member), deadline 72h
 *
 * Luồng xử lý steps:
 *   Start → Step 1 pending → Step 1 approved → Step 2 pending → ...→ Last step approved → Instance approved
 *   Bất kỳ step nào bị reject → Instance rejected ngay (early termination)
 *
 * Approver Types:
 *   sequential/any_of:
 *     - Tạo 1 WorkflowStepApproval với approver_id = NULL, approver_role = 'manager'
 *     - Bất kỳ user nào có role 'manager' trong org đều có thể approve
 *     - Step hoàn thành khi có 1 approval
 *
 *   parallel:
 *     - Tạo N WorkflowStepApprovals, 1 row per user có role trong org (resolve tại start time)
 *     - Step hoàn thành khi TẤT CẢ N rows đều có status = approved
 *     - Nếu bất kỳ row nào rejected → toàn bộ instance rejected
 *
 * SLA / Deadline:
 *   - deadline_hours: số giờ kể từ khi bước được kích hoạt
 *   - Khi hết hạn: WorkflowEngine.escalateExpired() đổi status → Expired
 *     và tạo 1 approval mới với approver_role = escalate_to_role
 *   - escalate_to_role = NULL → khi expired sẽ tự động approve (auto-approve on timeout)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definition_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /**
             * workflow_definition_id — FK tới workflow_definitions.
             *
             * Cascade delete: khi xóa definition → xóa luôn tất cả steps.
             * Lưu ý: KHÔNG xóa WorkflowInstances đang chạy (chúng tham chiếu definition_id riêng).
             */
            $table->uuid('workflow_definition_id')->comment('FK → workflow_definitions');
            $table->foreign('workflow_definition_id')
                ->references('id')
                ->on('workflow_definitions')
                ->cascadeOnDelete();

            /**
             * step_order — Thứ tự thực hiện bước, bắt đầu từ 1.
             *
             * Unique constraint (workflow_definition_id, step_order):
             *   Mỗi workflow chỉ có 1 bước cho mỗi số thứ tự.
             *
             * Gaps được chấp nhận nhưng không khuyến khích:
             *   VD: steps 1, 2, 5 → valid nhưng engine sẽ coi 3 & 4 là skip
             *   → Nên dùng liên tiếp: 1, 2, 3, ...
             */
            $table->unsignedSmallInteger('step_order')->comment('Thứ tự bước, bắt đầu từ 1');

            $table->string('name', 200)->comment('Tên bước hiển thị cho người dùng');
            $table->text('description')->nullable()->comment('Mô tả chi tiết bước này');

            /**
             * approver_role — Slug của role được phép phê duyệt bước này.
             *
             * Tham chiếu đến Role.slug trong pkg-omnify-laravel-sso.
             * Không có FK cứng để tránh coupling giữa packages — validation ở application layer.
             *
             * NULL = bất kỳ authenticated user nào cũng có thể approve (hiếm dùng)
             *
             * Ví dụ: 'manager', 'admin', 'finance_director', 'ceo'
             */
            $table->string('approver_role', 100)->nullable()->comment('Role slug được phép duyệt (từ RBAC system)');

            /**
             * type — Kiểu phê duyệt của bước.
             *
             * sequential (default): Bất kỳ 1 người có role → approve là xong
             * any_of:               Giống sequential, tường minh hơn
             * parallel:             TẤT CẢ người có role phải approve
             *
             * Xem chi tiết: WorkflowStepType enum
             */
            $table->string('type', 20)->default('sequential')->comment('sequential | any_of | parallel');

            /**
             * deadline_hours — Số giờ để hoàn thành bước (SLA).
             *
             * NULL = không có deadline (chờ vô thời hạn)
             * 24   = phải duyệt trong 24 giờ kể từ khi bước được kích hoạt
             *
             * Thời điểm bắt đầu đếm: khi WorkflowStepApproval được tạo (notified_at)
             *
             * Khi quá hạn:
             *   - workflow:escalate-expired command chạy định kỳ
             *   - Đánh dấu approval hiện tại → Expired
             *   - Tạo approval mới với escalate_to_role
             */
            $table->unsignedSmallInteger('deadline_hours')->nullable()->comment('SLA: số giờ để hoàn thành bước (NULL = không giới hạn)');

            /**
             * escalate_to_role — Role nhận bàn giao khi hết deadline.
             *
             * NULL = khi hết deadline → tự động approve (auto-approve on timeout)
             *        Dùng khi muốn workflow không bị block bởi approver không phản hồi.
             *
             * 'admin' = khi manager không duyệt trong 24h → chuyển lên admin
             *
             * Workflow khi escalate:
             *   Step approval hiện tại → status = Expired
             *   Tạo approval mới: approver_role = escalate_to_role, deadline = 2x original
             */
            $table->string('escalate_to_role', 100)->nullable()->comment('Role nhận bàn giao khi quá hạn (NULL = auto-approve)');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Unique: mỗi definition chỉ có 1 step per order
            $table->unique(['workflow_definition_id', 'step_order'], 'wf_def_steps_def_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definition_steps');
    }
};

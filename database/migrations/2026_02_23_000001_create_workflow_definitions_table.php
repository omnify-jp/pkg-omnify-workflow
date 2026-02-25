<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng workflow_definitions — template/mẫu của quy trình phê duyệt.
 *
 * Mục đích:
 *   Lưu trữ "khuôn mẫu" workflow. Mỗi record định nghĩa một loại quy trình
 *   (VD: "phê duyệt đơn nghỉ phép", "phê duyệt purchase order").
 *   Khi cần chạy một workflow, hệ thống clone template này thành WorkflowInstance.
 *
 * Luồng:
 *   WorkflowDefinition (template) ──── 1:N ────▶ WorkflowDefinitionStep (các bước)
 *   WorkflowDefinition (template) ──── 1:N ────▶ WorkflowInstance (các lần chạy thực tế)
 *
 * Nguồn định nghĩa (ưu tiên từ cao đến thấp):
 *   1. DB record với console_organization_id = org cụ thể   (org override)
 *   2. DB record với console_organization_id = NULL          (global default)
 *   3. config('workflow.definitions.{slug}')                 (config fallback)
 *
 * Ví dụ config fallback:
 *   'leave-approval' => [
 *       'name'  => 'Phê duyệt nghỉ phép',
 *       'steps' => [
 *           ['name' => 'Quản lý', 'approver_role' => 'manager', 'type' => 'sequential', 'deadline_hours' => 24],
 *           ['name' => 'Giám đốc', 'approver_role' => 'admin',  'type' => 'sequential', 'deadline_hours' => 48],
 *       ],
 *   ],
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            // Primary key — UUID để compatible với Console và cross-service
            $table->uuid('id')->primary();

            /**
             * slug — Định danh duy nhất của workflow, dùng trong code.
             *
             * Convention đặt tên: {domain}-{action}
             * VD: leave-approval, purchase-order-approval, document-review
             *
             * Unique trong phạm vi (console_organization_id, slug):
             *   - (null, 'leave-approval')         → global default
             *   - ('org-uuid', 'leave-approval')   → org-specific override
             */
            $table->string('slug', 100)->comment('Định danh duy nhất, dùng trong code');

            /**
             * console_organization_id — Scope theo tổ chức.
             *
             * NULL  = global definition, áp dụng cho tất cả tổ chức
             * UUID  = chỉ áp dụng cho tổ chức đó (override global)
             *
             * Khi WorkflowEngine tìm definition theo slug:
             *   1. Tìm record có đúng org_id trước (org-specific override)
             *   2. Nếu không có → tìm record có org_id = NULL (global)
             *   3. Nếu không có → fallback về config file
             */
            $table->string('console_organization_id', 36)->nullable()->comment('NULL = global, UUID = org-specific override');

            $table->string('name', 200)->comment('Tên hiển thị cho người dùng');
            $table->text('description')->nullable()->comment('Mô tả chi tiết quy trình');

            /**
             * is_active — Bật/tắt workflow definition.
             *
             * Khi false: WorkflowEngine sẽ báo lỗi nếu cố start workflow này.
             * Dùng để "disable" một quy trình tạm thời mà không cần xóa.
             */
            $table->boolean('is_active')->default(true)->comment('Có đang hoạt động không');

            /**
             * config — JSON config bổ sung, dự phòng cho extensions.
             *
             * Có thể dùng để lưu:
             *   - Custom fields cho workflow
             *   - Notification settings per-workflow
             *   - Metadata cho integration (VD: webhook URL)
             *
             * Format mẫu:
             *   {
             *     "notify_submitter_on_step_complete": true,
             *     "webhook_url": "https://...",
             *     "custom_fields": ["reason", "urgency_level"]
             *   }
             */
            $table->json('config')->nullable()->comment('JSON config bổ sung (extensions)');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Mỗi (org, slug) là unique — 1 org chỉ có 1 override per slug
            $table->unique(['console_organization_id', 'slug'], 'workflow_definitions_org_slug_unique');

            // Index để tìm kiếm nhanh theo org
            $table->index('console_organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definitions');
    }
};

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflow Definitions (Config-based)
    |--------------------------------------------------------------------------
    |
    | Định nghĩa các quy trình phê duyệt mặc định trong code.
    | Đây là "global defaults" — tổ chức có thể override trong DB.
    |
    | Thứ tự ưu tiên khi WorkflowEngine resolve definition:
    |   1. DB record: slug + console_organization_id = org cụ thể  (org override)
    |   2. DB record: slug + console_organization_id = NULL         (global DB override)
    |   3. config('workflow.definitions.{slug}')                    (fallback về file này)
    |
    | Cấu trúc 1 definition:
    |   '{slug}' => [
    |       'name'        => string,   // Tên hiển thị
    |       'description' => string,   // Mô tả (optional)
    |       'steps'       => [
    |           [
    |               'name'             => string,        // Tên bước
    |               'approver_role'    => string|null,   // Role slug từ RBAC ('manager', 'admin')
    |               'type'             => string,        // 'sequential' | 'any_of' | 'parallel'
    |               'deadline_hours'   => int|null,      // SLA: số giờ để duyệt
    |               'escalate_to_role' => string|null,   // Role nhận khi quá hạn (null = auto-approve)
    |           ],
    |           ...
    |       ],
    |   ],
    |
    | Ví dụ thực tế — bỏ comment để kích hoạt:
    |
    | 'leave-approval' => [
    |     'name'        => 'Phê duyệt đơn nghỉ phép',
    |     'description' => 'Quy trình 2 cấp: quản lý → giám đốc',
    |     'steps' => [
    |         [
    |             'name'             => 'Quản lý trực tiếp',
    |             'approver_role'    => 'manager',
    |             'type'             => 'sequential',
    |             'deadline_hours'   => 24,
    |             'escalate_to_role' => 'admin',
    |         ],
    |         [
    |             'name'             => 'Ban giám đốc',
    |             'approver_role'    => 'admin',
    |             'type'             => 'sequential',
    |             'deadline_hours'   => 48,
    |             'escalate_to_role' => null,  // null = auto-approve khi quá hạn
    |         ],
    |     ],
    | ],
    |
    | 'purchase-order-approval' => [
    |     'name' => 'Phê duyệt Purchase Order',
    |     'steps' => [
    |         ['name' => 'Kế toán',    'approver_role' => 'accountant', 'type' => 'sequential', 'deadline_hours' => 8],
    |         ['name' => 'Quản lý',   'approver_role' => 'manager',    'type' => 'sequential', 'deadline_hours' => 24],
    |         ['name' => 'Giám đốc',  'approver_role' => 'admin',      'type' => 'sequential', 'deadline_hours' => 48],
    |     ],
    | ],
    |
    */
    'definitions' => [
        // Thêm definitions của service vào đây
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Cấu hình các routes của workflow package.
    |
    | prefix:     URL prefix cho tất cả routes (thay đổi qua WORKFLOW_ROUTE_PREFIX)
    | middleware: Middleware áp dụng cho tất cả routes
    |
    | Routes được mount:
    |   GET  /{prefix}/pending               → Danh sách việc cần duyệt
    |   GET  /{prefix}/instances/{id}        → Chi tiết instance
    |   POST /{prefix}/instances/{id}/approve
    |   POST /{prefix}/instances/{id}/reject
    |   POST /{prefix}/instances/{id}/cancel
    |
    */
    'routes' => [
        'enabled' => env('WORKFLOW_ROUTES_ENABLED', true),
        'prefix' => env('WORKFLOW_ROUTE_PREFIX', 'api/workflow'),
        'middleware' => ['web', 'core.auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Panel
    |--------------------------------------------------------------------------
    |
    | Cấu hình admin pages cho quản lý workflow definitions & instances.
    | Admin routes render Inertia pages + JSON API cho CRUD operations.
    |
    | Routes được mount:
    |   GET    /{prefix}/definitions           → Danh sách definitions
    |   POST   /{prefix}/definitions           → Tạo definition
    |   PUT    /{prefix}/definitions/{id}      → Update definition
    |   DELETE /{prefix}/definitions/{id}      → Xóa definition
    |   GET    /{prefix}/instances             → Danh sách instances
    |   GET    /{prefix}/instances/{id}        → Chi tiết instance
    |
    */
    'admin' => [
        'enabled' => env('WORKFLOW_ADMIN_ENABLED', true),
        'prefix' => env('WORKFLOW_ADMIN_PREFIX', 'admin/workflow'),
        'middleware' => ['web', 'auth'],
        'pages_path' => 'admin/workflow',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Package fire events khi workflow thay đổi trạng thái.
    | Host application lắng nghe events và gửi notifications.
    |
    | Events được fire:
    |   WorkflowSubmitted      → Khi workflow được tạo (notify approver step 1)
    |   WorkflowStepCompleted  → Khi 1 step xong (notify approver bước tiếp)
    |   WorkflowApproved       → Khi workflow approved (notify submitter)
    |   WorkflowRejected       → Khi workflow rejected (notify submitter + lý do)
    |   WorkflowCancelled      → Khi workflow bị hủy
    |
    | Để lắng nghe events, đăng ký listeners trong EventServiceProvider của app:
    |   Event::listen(WorkflowSubmitted::class, YourNotificationListener::class);
    |
    */
    'notifications' => [
        'enabled' => env('WORKFLOW_NOTIFICATIONS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | Cấu hình cho Artisan command: php artisan workflow:escalate-expired
    |
    | Recommended: Schedule hourly hoặc every 15 minutes trong routes/console.php:
    |   use Illuminate\Support\Facades\Schedule;
    |   Schedule::command('workflow:escalate-expired')->hourly();
    |
    */
    'scheduler' => [
        'escalation_check_minutes' => env('WORKFLOW_ESCALATION_CHECK_MINUTES', 60),
    ],

];

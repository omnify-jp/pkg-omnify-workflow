# Getting Started

## 1. Cài đặt

```bash
composer require omnifyjp/omnify-client-laravel-workflow
```

## 2. Publish và migrate

```bash
php artisan vendor:publish --provider="Omnify\Workflow\WorkflowServiceProvider" --tag=workflow-migrations
php artisan vendor:publish --provider="Omnify\Workflow\WorkflowServiceProvider" --tag=workflow-config
php artisan migrate
```

## 3. Cấu hình `config/workflow.php`

```php
return [
    'definitions' => [
        'leave-approval' => [
            'name' => 'Phê duyệt nghỉ phép',
            'steps' => [
                [
                    'name'           => 'Quản lý trực tiếp',
                    'approver_role'  => 'manager',
                    'type'           => 'sequential',   // sequential | parallel | any_of
                    'deadline_hours' => 24,
                    'escalate_to_role' => 'admin',      // null = auto-approve khi hết hạn
                ],
                [
                    'name'           => 'Giám đốc',
                    'approver_role'  => 'admin',
                    'type'           => 'sequential',
                    'deadline_hours' => 48,
                ],
            ],
        ],
    ],

    'routes' => [
        'enabled'    => env('WORKFLOW_ROUTES_ENABLED', true),
        'prefix'     => env('WORKFLOW_ROUTE_PREFIX', 'api/workflow'),
        'middleware' => ['web', 'auth'],
    ],
];
```

## 4. Gắn workflow vào model

```php
use Omnify\Workflow\Models\Traits\HasWorkflow;

class LeaveRequest extends Model
{
    use HasWorkflow;
}
```

## 5. Submit workflow

```php
$leaveRequest = LeaveRequest::create([...]);

$instance = $leaveRequest->startWorkflow(
    slug: 'leave-approval',
    submitter: $user,
    metadata: ['days' => 3, 'reason' => 'Nghỉ phép năm'],
);
// $instance->status === WorkflowStatus::Pending
```

## 6. Approve / Reject

```php
use Omnify\Workflow\Services\WorkflowEngine;

$engine = app(WorkflowEngine::class);

// Phê duyệt
$engine->approve($instance, $approverUser, comment: 'Đồng ý');

// Từ chối (comment bắt buộc)
$engine->reject($instance, $approverUser, comment: 'Thiếu giấy tờ');

// Hủy
$engine->cancel($instance, $adminUser, comment: 'Hủy theo yêu cầu');
```

## 7. Lắng nghe events

```php
// app/Listeners/NotifyApprover.php
use Omnify\Workflow\Events\WorkflowSubmitted;

class NotifyApprover
{
    public function handle(WorkflowSubmitted $event): void
    {
        // $event->instance — WorkflowInstance
        // Gửi email/notification tới approvers
    }
}
```

Events có sẵn: `WorkflowSubmitted`, `WorkflowApproved`, `WorkflowRejected`, `WorkflowCancelled`, `WorkflowStepCompleted`.

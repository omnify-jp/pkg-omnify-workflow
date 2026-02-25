# Defining Workflows

Workflow definitions xác định cấu trúc các bước phê duyệt. Có 2 cách định nghĩa:

## 1. Định nghĩa qua Config (mặc định)

Thêm vào `config/workflow.php`:

```php
return [
    'definitions' => [
        'leave-approval' => [
            'name' => 'Phê duyệt nghỉ phép',
            'steps' => [
                [
                    'name'             => 'Quản lý trực tiếp',
                    'approver_role'    => 'manager',
                    'type'             => 'sequential',  // sequential | parallel | any_of
                    'deadline_hours'   => 24,
                    'escalate_to_role' => 'admin',       // null = tự approve khi hết hạn
                ],
                [
                    'name'           => 'Giám đốc',
                    'approver_role'  => 'admin',
                    'type'           => 'sequential',
                    'deadline_hours' => 48,
                ],
            ],
        ],

        'purchase-approval' => [
            'name' => 'Phê duyệt mua sắm',
            'steps' => [
                [
                    'name'           => 'Team trưởng',
                    'approver_role'  => 'team_lead',
                    'type'           => 'any_of',
                    'deadline_hours' => 8,
                ],
                [
                    'name'           => 'Ban kế toán (tất cả phải duyệt)',
                    'approver_role'  => 'accountant',
                    'type'           => 'parallel',      // TẤT CẢ user có role này phải approve
                    'deadline_hours' => 24,
                ],
                [
                    'name'           => 'CEO',
                    'approver_role'  => 'ceo',
                    'type'           => 'sequential',
                    'deadline_hours' => 72,
                    'escalate_to_role' => null,          // Auto-approve khi hết hạn
                ],
            ],
        ],
    ],
];
```

## 2. Định nghĩa qua Database

Tạo row trong bảng `workflow_definitions` và các bước trong `workflow_definition_steps`.

DB definitions ưu tiên hơn config:
1. Tìm DB definition có `slug` + `console_organization_id` khớp (org-specific)
2. Tìm DB definition có `slug` + `console_organization_id = null` (global)
3. Fallback về config

```php
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;

$def = WorkflowDefinition::create([
    'slug'    => 'leave-approval',
    'name'    => 'Phê duyệt nghỉ phép (Override)',
    'is_active' => true,
    'console_organization_id' => 'org-uuid',  // null = global override
]);

WorkflowDefinitionStep::create([
    'workflow_definition_id' => $def->id,
    'step_order'             => 1,
    'name'                   => 'HR Manager',
    'approver_role'          => 'hr_manager',
    'type'                   => 'sequential',
    'deadline_hours'         => 48,
]);
```

## Step Types

| Type | Hành vi |
|------|---------|
| `sequential` | Bất kỳ 1 user có role đó đều có thể approve (1 row tạo ra) |
| `any_of` | Alias của `sequential` — ngữ nghĩa rõ ràng hơn |
| `parallel` | **TẤT CẢ** user có role đó phải approve (N rows tạo ra) |

> **Lưu ý**: Với `parallel`, số lượng approvers được resolve tại thời điểm `start()`. Nếu sau đó có user mới được gán role, họ sẽ **không** được thêm vào bước đang chạy.

## SLA & Escalation

- `deadline_hours`: số giờ để approve bước này kể từ khi bước bắt đầu.
- `escalate_to_role`: nếu hết hạn, tạo approval mới cho role này (deadline x2).
  - `null` = tự động approve (auto-approve) khi hết hạn.

Lên lịch kiểm tra escalation:

```php
// routes/console.php hoặc AppServiceProvider
Schedule::command('workflow:escalate-expired')->hourly();
```

## Approver Override

Khi gọi `startWorkflow()`, có thể chỉ định approver cụ thể thay vì để engine resolve theo role:

```php
$instance = $model->startWorkflow(
    slug: 'leave-approval',
    submitter: $user,
    approverOverrides: [
        1 => $specificManagerUserId,  // Bước 1: gán cụ thể
        // Bước 2: không override → engine resolve theo role
    ],
    metadata: ['days' => 3],
);
```

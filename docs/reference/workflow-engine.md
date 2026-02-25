# WorkflowEngine Reference

`Omnify\Workflow\Services\WorkflowEngine` — service xử lý tất cả logic workflow.

Inject qua constructor hoặc `app(WorkflowEngine::class)`.

---

## `start()`

```php
public function start(
    Model $model,
    string $definitionSlug,
    Model $submitter,
    array $approverOverrides = [],
    array $metadata = [],
): WorkflowInstance
```

Tạo workflow instance mới cho `$model`.

**Throws** `RuntimeException` nếu:
- `$model` đã có active workflow (`hasActiveWorkflow()`)
- Definition không tồn tại hoặc không active
- Definition không có steps

**Ví dụ:**

```php
$engine = app(WorkflowEngine::class);

$instance = $engine->start(
    model: $leaveRequest,
    definitionSlug: 'leave-approval',
    submitter: $user,
    metadata: ['days' => 3],
);
```

> Thay vào đó nên dùng `$model->startWorkflow()` cho tiện hơn.

---

## `approve()`

```php
public function approve(
    WorkflowInstance $instance,
    Model $approver,
    ?string $comment = null,
): void
```

Phê duyệt bước hiện tại.

- Nếu bước là `sequential`/`any_of`: người dùng có role phù hợp có thể approve.
- Nếu bước là `parallel`: mỗi người phải approve row của mình.
- Nếu bước hoàn thành: tự động chuyển sang bước tiếp theo.
- Nếu đây là bước cuối: instance chuyển sang `approved`.

**Fires:** `WorkflowStepCompleted`, `WorkflowApproved`

**Throws** `RuntimeException` nếu:
- Instance đã complete (không phải `pending`)
- Approver không có quyền (sai role, không có row)

---

## `reject()`

```php
public function reject(
    WorkflowInstance $instance,
    Model $approver,
    string $comment,       // BẮT BUỘC
): void
```

Từ chối — kết thúc workflow ngay lập tức, bất kể còn bao nhiêu bước.

**Fires:** `WorkflowRejected`

**Throws** `RuntimeException` nếu:
- `$comment` rỗng
- Instance đã complete

---

## `cancel()`

```php
public function cancel(
    WorkflowInstance $instance,
    Model $actor,
    string $reason,        // BẮT BUỘC
): void
```

Hủy workflow. Chỉ submitter hoặc admin mới được hủy (được kiểm tra ở controller layer).

**Fires:** `WorkflowCancelled`

**Throws** `RuntimeException` nếu instance đã complete.

---

## `escalateExpired()`

```php
public function escalateExpired(): int
```

Tìm tất cả approval đã quá `deadline_at` và xử lý:

| `escalate_to_role` | Hành động |
|--------------------|-----------|
| Có giá trị | Tạo row approval mới cho role đó, deadline x2 |
| `null` | Auto-approve — tạo fake Approved row |

Trả về số approval được xử lý.

Chạy qua Artisan:

```bash
php artisan workflow:escalate-expired
```

Hoặc lên lịch:

```php
// routes/console.php
Schedule::command('workflow:escalate-expired')->hourly();
```

---

## Events

| Event | Khi nào fire |
|-------|-------------|
| `WorkflowSubmitted` | Sau `start()` |
| `WorkflowStepCompleted` | Khi tất cả approvals của 1 bước complete |
| `WorkflowApproved` | Khi bước cuối approve xong |
| `WorkflowRejected` | Sau `reject()` |
| `WorkflowCancelled` | Sau `cancel()` |

**Lắng nghe events:**

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \Omnify\Workflow\Events\WorkflowSubmitted::class  => [NotifyApprovers::class],
    \Omnify\Workflow\Events\WorkflowApproved::class   => [NotifySubmitter::class],
    \Omnify\Workflow\Events\WorkflowRejected::class   => [NotifySubmitter::class],
    \Omnify\Workflow\Events\WorkflowCancelled::class  => [NotifyApprovers::class],
];

// Listener example
class NotifyApprovers
{
    public function handle(WorkflowSubmitted $event): void
    {
        // $event->instance — WorkflowInstance
        // Gửi mail/push notification tới approvers
        $instance = $event->instance->load('definition', 'stepApprovals');
        // ...
    }
}
```

---

## User Resolver

Engine cần resolve "tất cả user có role X trong org Y" cho `parallel` steps và authorization checks.

Resolver được bind trong `WorkflowServiceProvider`:

```php
// packages/pkg-omnify-workflow/app/WorkflowServiceProvider.php
$this->app->singleton(WorkflowEngine::class, function ($app) {
    return new WorkflowEngine(
        userResolver: function (string $role, ?string $orgId): Collection {
            return User::whereHas('roles', fn($q) =>
                $q->where('slug', $role)
                  ->where(fn($q2) =>
                      $q2->whereNull('console_organization_id')
                         ->orWhere('console_organization_id', $orgId)
                  )
            )->get();
        },
    );
});
```

Để override resolver (ví dụ: custom user model):

```php
// app/Providers/AppServiceProvider.php
$this->app->extend(WorkflowEngine::class, function (WorkflowEngine $engine, $app) {
    // Rebind với custom resolver
    return new WorkflowEngine(
        userResolver: fn(string $role, ?string $orgId) => MyUser::byRole($role, $orgId)->get(),
    );
});
```

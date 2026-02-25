# Using HasWorkflow Trait

## Gắn vào Model

```php
use Omnify\Workflow\Models\Traits\HasWorkflow;

class LeaveRequest extends Model
{
    use HasWorkflow;
}
```

## Các Methods

### `startWorkflow()`

```php
$instance = $leaveRequest->startWorkflow(
    slug: 'leave-approval',
    submitter: $user,
    approverOverrides: [],   // optional: ['step_order' => 'user_id']
    metadata: ['days' => 3], // optional: dữ liệu bổ sung
);
// $instance->status === WorkflowStatus::Pending
```

**Throws** `RuntimeException` nếu model đã có active workflow.

---

### `activeWorkflow()`

Trả về `WorkflowInstance` đang `pending`, hoặc `null`.

```php
$instance = $leaveRequest->activeWorkflow();
if ($instance) {
    echo $instance->status->value; // 'pending'
}
```

---

### `latestWorkflow()`

Trả về `WorkflowInstance` gần nhất (bất kỳ status), hoặc `null`.

```php
$latest = $leaveRequest->latestWorkflow();
echo $latest?->status->value; // 'approved', 'rejected', 'pending'...
```

---

### `hasActiveWorkflow()`

```php
if ($leaveRequest->hasActiveWorkflow()) {
    // Đang chờ duyệt, không cho submit lại
}
```

---

### `workflowStatus()`

```php
$status = $leaveRequest->workflowStatus(); // ?WorkflowStatus enum
echo $status?->label(); // 'Pending', 'Approved'...
```

---

### `workflowInstances()`

Tất cả instances (bao gồm lịch sử):

```php
$allInstances = $leaveRequest->workflowInstances()->latest()->get();
```

---

## Ví dụ thực tế

```php
class LeaveRequestController extends Controller
{
    public function store(Request $request)
    {
        $leaveRequest = LeaveRequest::create($request->validated());

        // Start workflow
        $instance = $leaveRequest->startWorkflow(
            slug: 'leave-approval',
            submitter: $request->user(),
            metadata: [
                'days'   => $request->days,
                'reason' => $request->reason,
            ],
        );

        return redirect()->route('workflow.instances.show', $instance);
    }

    public function show(LeaveRequest $leaveRequest)
    {
        $status = $leaveRequest->workflowStatus();
        $activeInstance = $leaveRequest->activeWorkflow();

        return Inertia::render('leave-requests/show', [
            'leaveRequest'   => $leaveRequest,
            'workflowStatus' => $status?->value,
            'activeInstance' => $activeInstance,
        ]);
    }
}
```

---

## Morph Relationship

`HasWorkflow` sử dụng morph relationship — một model có thể có nhiều workflow instances theo thời gian.

```
workflow_instances
├── workflowable_type = "App\\Models\\LeaveRequest"
├── workflowable_id   = "uuid-of-leave-request"
└── ...
```

Nhiều model khác nhau có thể dùng cùng workflow definition:

```php
class PurchaseOrder extends Model
{
    use HasWorkflow;
}

class TravelRequest extends Model
{
    use HasWorkflow;
}

// Cả 3 đều có thể dùng 'leave-approval' hoặc bất kỳ slug nào
```

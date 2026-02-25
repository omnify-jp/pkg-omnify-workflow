<?php

declare(strict_types=1);

namespace Omnify\Workflow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Omnify\Workflow\Models\WorkflowInstance;

/**
 * WorkflowApproved — Fire khi toàn bộ workflow được phê duyệt thành công.
 *
 * Host application nên lắng nghe event này để:
 *   - Gửi email chúc mừng cho submitter
 *   - Thực hiện business action (VD: tạo HR record, phát hành PO, etc.)
 *   - Cập nhật trạng thái của model chủ (LeaveRequest.status = 'approved')
 *
 * Ví dụ listener:
 *   ```php
 *   class ProcessApprovedLeaveRequest
 *   {
 *       public function handle(WorkflowApproved $event): void
 *       {
 *           $leaveRequest = $event->instance->workflowable;
 *           $leaveRequest->update(['status' => 'approved']);
 *
 *           $submitter = User::find($event->instance->submitted_by);
 *           $submitter?->notify(new LeaveRequestApproved($leaveRequest));
 *       }
 *   }
 *   ```
 */
class WorkflowApproved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Instance đã được phê duyệt hoàn toàn */
        public readonly WorkflowInstance $instance,
    ) {}
}

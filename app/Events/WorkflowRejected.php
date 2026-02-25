<?php

declare(strict_types=1);

namespace Omnify\Workflow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Models\WorkflowStepApproval;

/**
 * WorkflowRejected — Fire khi 1 approver từ chối, kết thúc workflow ngay lập tức.
 *
 * Host application nên lắng nghe event này để:
 *   - Thông báo cho submitter biết lý do từ chối
 *   - Rollback business state nếu cần
 *   - Cho phép submitter sửa và submit lại
 *
 * Ví dụ listener:
 *   ```php
 *   class HandleRejectedLeaveRequest
 *   {
 *       public function handle(WorkflowRejected $event): void
 *       {
 *           $leaveRequest = $event->instance->workflowable;
 *           $leaveRequest->update(['status' => 'rejected']);
 *
 *           $submitter = User::find($event->instance->submitted_by);
 *           $submitter?->notify(new LeaveRequestRejected(
 *               $leaveRequest,
 *               $event->rejection->comment
 *           ));
 *       }
 *   }
 *   ```
 */
class WorkflowRejected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Instance bị từ chối */
        public readonly WorkflowInstance $instance,
        /** Approval row chứa thông tin người từ chối và comment */
        public readonly WorkflowStepApproval $rejection,
    ) {}
}

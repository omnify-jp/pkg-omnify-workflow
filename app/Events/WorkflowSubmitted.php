<?php

declare(strict_types=1);

namespace Omnify\Workflow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Omnify\Workflow\Models\WorkflowInstance;

/**
 * WorkflowSubmitted — Được fire khi 1 workflow instance được tạo và submit.
 *
 * Host application nên lắng nghe event này để:
 *   - Gửi email/notification cho approver(s) của step 1
 *   - Ghi log business
 *   - Trigger integration (VD: Slack, webhook)
 *
 * Ví dụ listener:
 *   ```php
 *   class SendWorkflowNotification
 *   {
 *       public function handle(WorkflowSubmitted $event): void
 *       {
 *           $approvals = $event->instance->currentStepApprovals()->get();
 *           foreach ($approvals as $approval) {
 *               $approver = User::find($approval->approver_id)
 *                   ?? User::whereHas('roles', fn($q) => $q->where('slug', $approval->approver_role))->first();
 *               $approver?->notify(new WorkflowApprovalRequested($event->instance));
 *           }
 *       }
 *   }
 *   ```
 */
class WorkflowSubmitted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Instance vừa được submit */
        public readonly WorkflowInstance $instance,
    ) {}
}

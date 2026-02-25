<?php

declare(strict_types=1);

namespace Omnify\Workflow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Omnify\Workflow\Models\WorkflowInstance;

/**
 * WorkflowStepCompleted — Fire khi 1 step hoàn thành và sẵn sàng chuyển sang step tiếp.
 *
 * Host application nên lắng nghe event này để:
 *   - Gửi notification cho approver của step tiếp theo
 *   - Cập nhật UI realtime (broadcast)
 *   - Log progress
 *
 * Lưu ý: Event này fire TRƯỚC khi instance.current_step được tăng lên.
 * Sau khi event fire, WorkflowEngine sẽ:
 *   - Nếu còn step tiếp: tăng current_step, tạo approvals mới, fire WorkflowSubmitted again
 *   - Nếu đây là step cuối: đổi status → Approved, fire WorkflowApproved
 */
class WorkflowStepCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Instance của workflow đang chạy */
        public readonly WorkflowInstance $instance,
        /** Số thứ tự của step vừa hoàn thành */
        public readonly int $completedStep,
    ) {}
}

<?php

declare(strict_types=1);

namespace Omnify\Workflow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Omnify\Workflow\Models\WorkflowInstance;

/**
 * WorkflowCancelled — Fire khi submitter hoặc admin hủy workflow.
 *
 * Host application nên lắng nghe event này để:
 *   - Cập nhật trạng thái của model chủ
 *   - Thông báo cho các approver đang chờ biết workflow đã bị hủy
 *
 * Ai có thể hủy:
 *   - Submitter: hủy request của chính mình (VD: đơn nghỉ phép không cần nữa)
 *   - Admin: hủy thay (VD: request có vấn đề, cần submit lại)
 */
class WorkflowCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Instance bị hủy */
        public readonly WorkflowInstance $instance,
        /** UUID của người thực hiện hủy */
        public readonly string $cancelledBy,
        /** Lý do hủy */
        public readonly string $reason,
    ) {}
}

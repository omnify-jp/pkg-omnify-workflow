<?php

declare(strict_types=1);

namespace Omnify\Workflow\Enums;

/**
 * WorkflowStepApprovalStatus — Trạng thái của 1 bản ghi phê duyệt.
 *
 * Mỗi `workflow_step_approvals` row đại diện cho:
 *   - 1 lần phê duyệt của 1 approver (sequential/any_of: approver_id = null)
 *   - 1 lần phê duyệt của 1 user cụ thể (parallel: approver_id = user.id)
 *
 * Sơ đồ chuyển trạng thái:
 *
 *   ┌─────────┐
 *   │ Pending │  ← Mới tạo khi bắt đầu step
 *   └────┬────┘
 *        │
 *        ├──── approve() ──────▶ ┌──────────┐
 *        │                       │ Approved │
 *        │                       └──────────┘
 *        │
 *        ├──── reject()  ──────▶ ┌──────────┐
 *        │                       │ Rejected │ (toàn bộ workflow bị rejected)
 *        │                       └──────────┘
 *        │
 *        ├──── delegate()─────▶  ┌───────────┐
 *        │                       │ Delegated │ (chuyển cho người khác — future)
 *        │                       └───────────┘
 *        │
 *        └──── deadline hết ──▶  ┌─────────┐
 *                                │ Expired │ → tạo approval mới cho escalate_to_role
 *                                └─────────┘
 *
 * Lưu ý:
 *   - Một khi đã không còn Pending, row này không thay đổi nữa (immutable)
 *   - Khi Expired, WorkflowEngine tạo 1 approval row MỚI với escalate_to_role
 */
enum WorkflowStepApprovalStatus: string
{
    /** Đang chờ approver xử lý */
    case Pending = 'pending';

    /** Đã phê duyệt bởi approver */
    case Approved = 'approved';

    /** Đã từ chối — kéo theo toàn bộ WorkflowInstance bị Rejected */
    case Rejected = 'rejected';

    /** Đã ủy quyền cho người khác (future feature) */
    case Delegated = 'delegated';

    /**
     * Quá hạn SLA — WorkflowEngine.escalateExpired() đánh dấu row này
     * và tạo row mới với escalate_to_role.
     */
    case Expired = 'expired';

    /** Kiểm tra xem approval này còn đang chờ xử lý không */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** Kiểm tra xem approval này đã kết thúc chưa */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Delegated, self::Expired]);
    }

    /** Label tiếng Việt */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ duyệt',
            self::Approved => 'Đã phê duyệt',
            self::Rejected => 'Đã từ chối',
            self::Delegated => 'Đã ủy quyền',
            self::Expired => 'Quá hạn',
        };
    }

    /** Màu sắc cho UI badge */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Delegated => 'blue',
            self::Expired => 'orange',
        };
    }
}

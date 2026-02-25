<?php

declare(strict_types=1);

namespace Omnify\Workflow\Enums;

/**
 * WorkflowStatus — Trạng thái của một workflow instance.
 *
 * Sơ đồ chuyển trạng thái (State Machine):
 *
 *   ┌──────────┐
 *   │  Draft   │  ← Mới tạo nhưng chưa submit (hiện không dùng, reserved)
 *   └────┬─────┘
 *        │ start() / submit()
 *        ▼
 *   ┌──────────┐   approve() step cuối cùng    ┌──────────┐
 *   │ Pending  │ ──────────────────────────────▶│ Approved │
 *   └────┬─────┘                                └──────────┘
 *        │
 *        │ reject()   ┌──────────┐
 *        └───────────▶│ Rejected │
 *        │            └──────────┘
 *        │
 *        │ cancel()   ┌──────────────┐
 *        └───────────▶│  Cancelled   │
 *                     └──────────────┘
 *
 * Trạng thái terminal (không thể chuyển tiếp):
 *   - Approved
 *   - Rejected
 *   - Cancelled
 */
enum WorkflowStatus: string
{
    /** Mới tạo nhưng chưa submit — reserved cho future use */
    case Draft = 'draft';

    /** Đang chờ phê duyệt — có ít nhất 1 step chưa hoàn thành */
    case Pending = 'pending';

    /** Đã được phê duyệt — tất cả steps đã approved */
    case Approved = 'approved';

    /** Đã bị từ chối — bất kỳ approver nào reject là kết thúc ngay */
    case Rejected = 'rejected';

    /** Đã bị hủy — submitter hoặc admin hủy */
    case Cancelled = 'cancelled';

    /** Trả về label tiếng Việt cho UI */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Pending => 'Đang chờ duyệt',
            self::Approved => 'Đã phê duyệt',
            self::Rejected => 'Đã từ chối',
            self::Cancelled => 'Đã hủy',
        };
    }

    /** Kiểm tra xem workflow còn đang hoạt động không (chưa kết thúc) */
    public function isActive(): bool
    {
        return in_array($this, [self::Draft, self::Pending]);
    }

    /** Kiểm tra xem workflow đã kết thúc chưa (terminal state) */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled]);
    }

    /** Màu sắc cho UI badge */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'yellow',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Cancelled => 'gray',
        };
    }
}

<?php

declare(strict_types=1);

namespace Omnify\Workflow\Enums;

/**
 * WorkflowStepType — Kiểu phê duyệt của một bước trong workflow.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  Type        │  Approvers được tạo  │  Điều kiện hoàn thành step       │
 * ├──────────────┼──────────────────────┼──────────────────────────────────┤
 * │  Sequential  │  1 row (any of role) │  Bất kỳ 1 người có role duyệt   │
 * │  AnyOf       │  1 row (any of role) │  Bất kỳ 1 người có role duyệt   │
 * │  Parallel    │  N rows (all users)  │  TẤT CẢ người có role phải duyệt│
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Chi tiết:
 *
 * Sequential / AnyOf:
 *   - Chỉ tạo 1 `workflow_step_approvals` row với approver_id = NULL
 *   - Bất kỳ user nào có role tương ứng trong org đều có thể approve
 *   - Khi approve xong → advance to next step
 *   - Phù hợp cho hầu hết approval flows (VD: "bất kỳ manager nào duyệt")
 *
 * Parallel:
 *   - Tạo N `workflow_step_approvals` rows, mỗi row gán 1 user cụ thể
 *   - Step chỉ complete khi TẤT CẢ N người đã approved
 *   - Nếu bất kỳ 1 người reject → workflow bị rejected ngay
 *   - Phù hợp cho: "tất cả thành viên ban giám đốc phải ký"
 *   - Yêu cầu WorkflowEngine resolve danh sách user tại thời điểm start()
 */
enum WorkflowStepType: string
{
    /**
     * Tuần tự — bất kỳ 1 người có role duyệt là xong.
     * Tên "sequential" ám chỉ thứ tự giữa các STEP, không phải giữa approvers.
     */
    case Sequential = 'sequential';

    /**
     * Bất kỳ — alias của Sequential, tường minh hơn về ý nghĩa.
     * "any one of the role members can approve"
     */
    case AnyOf = 'any_of';

    /**
     * Song song — TẤT CẢ người có role phải duyệt.
     * Tất cả approvers xử lý đồng thời (không theo thứ tự).
     */
    case Parallel = 'parallel';

    /** Label tiếng Việt */
    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'Tuần tự (bất kỳ 1 người)',
            self::AnyOf => 'Bất kỳ ai (any of role)',
            self::Parallel => 'Song song (tất cả phải duyệt)',
        };
    }

    /**
     * Kiểm tra xem type có yêu cầu resolve danh sách user cụ thể không.
     * Chỉ Parallel mới cần pre-resolve users vì phải tạo N rows.
     */
    public function requiresUserResolution(): bool
    {
        return $this === self::Parallel;
    }
}

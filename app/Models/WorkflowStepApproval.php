<?php

declare(strict_types=1);

namespace Omnify\Workflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnify\Workflow\Enums\WorkflowStepApprovalStatus;

class WorkflowStepApproval extends Model
{
    use HasUuids;

    protected $table = 'workflow_step_approvals';

    protected $fillable = [
        'workflow_instance_id',
        'workflow_definition_step_id',
        'step_order',
        'approver_id',
        'approver_role',
        'status',
        'comment',
        'decided_at',
        'deadline_at',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'status' => WorkflowStepApprovalStatus::class,
            'decided_at' => 'datetime',
            'deadline_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /** WorkflowInstance chứa approval này. */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    /** Step template của approval này. */
    public function definitionStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinitionStep::class, 'workflow_definition_step_id');
    }

    /** Kiểm tra approval này đang chờ xử lý. */
    public function isPending(): bool
    {
        return $this->status === WorkflowStepApprovalStatus::Pending;
    }

    /** Kiểm tra approval này đã quá hạn SLA chưa. */
    public function isOverdue(): bool
    {
        return $this->isPending()
            && $this->deadline_at !== null
            && $this->deadline_at->isPast();
    }

    /**
     * Kiểm tra một user có quyền approve row này không.
     *
     * Logic:
     *   - Nếu approver_id đã set (parallel): chỉ đúng user đó mới approve được
     *   - Nếu approver_id = null (sequential/any_of): bất kỳ user có role đều approve được
     *     (role check được thực hiện ở WorkflowEngine, không phải ở đây)
     */
    public function canBeApprovedBy(string $userId, bool $hasRequiredRole = true): bool
    {
        if (! $this->isPending()) {
            return false;
        }

        if ($this->approver_id !== null) {
            return $this->approver_id === $userId;
        }

        return $hasRequiredRole;
    }
}

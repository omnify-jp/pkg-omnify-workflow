<?php

declare(strict_types=1);

namespace Omnify\Workflow\Services\Handlers;

use Closure;
use Omnify\Workflow\Enums\WorkflowStepApprovalStatus;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Models\WorkflowStepApproval;

/**
 * Handler for sequential and any_of step types.
 *
 * Creates exactly one approval row per step. For sequential steps the approver
 * may be pre-assigned via approverOverrides. For any_of steps the first user
 * with the required role may claim the row (approver_id remains null until action).
 */
class SerialStepHandler implements StepHandlerInterface
{
    /**
     * Create a single WorkflowStepApproval row for sequential or any_of steps.
     *
     * The approver_id is taken from approverOverrides if present, otherwise null
     * (meaning any user with the required role may action the step).
     */
    public function createApprovals(
        WorkflowInstance $instance,
        WorkflowDefinitionStep $step,
        array $approverOverrides,
        ?string $orgId,
        ?Closure $userResolver = null,
    ): void {
        $stepOrder = $step->step_order;
        $overrideKey = "step_{$stepOrder}";
        $specificApproverId = $approverOverrides[$overrideKey] ?? null;
        $definitionStepId = $step->exists ? $step->id : null;

        WorkflowStepApproval::create([
            'workflow_instance_id' => $instance->id,
            'workflow_definition_step_id' => $definitionStepId,
            'step_order' => $stepOrder,
            'approver_id' => $specificApproverId,
            'approver_role' => $step->approver_role,
            'status' => WorkflowStepApprovalStatus::Pending->value,
            'deadline_at' => $step->calculateDeadlineAt(),
        ]);
    }

    /**
     * Check whether the current step is complete.
     *
     * Complete when at least one approval row is Approved and no rows remain Pending.
     */
    public function isComplete(WorkflowInstance $instance): bool
    {
        $pendingCount = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
            ->where('step_order', $instance->current_step)
            ->where('status', WorkflowStepApprovalStatus::Pending->value)
            ->count();

        $approvedCount = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
            ->where('step_order', $instance->current_step)
            ->where('status', WorkflowStepApprovalStatus::Approved->value)
            ->count();

        return $approvedCount > 0 && $pendingCount === 0;
    }
}

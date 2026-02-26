<?php

declare(strict_types=1);

namespace Omnify\Workflow\Services\Handlers;

use Closure;
use Omnify\Workflow\Enums\WorkflowStepApprovalStatus;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Models\WorkflowStepApproval;

/**
 * Handler for parallel step types.
 *
 * Creates N approval rows — one per user resolved by the approver_role in the
 * given organization. All assigned approvers must approve before the step
 * advances. Falls back to a single row (serial behaviour) when no users are
 * found for the role, to avoid blocking the workflow.
 */
class ParallelStepHandler implements StepHandlerInterface
{
    /**
     * Create one WorkflowStepApproval row per resolved user for parallel steps.
     *
     * When $userResolver returns an empty collection (no users found for the role),
     * falls back to creating a single row so the workflow is never permanently blocked.
     */
    public function createApprovals(
        WorkflowInstance $instance,
        WorkflowDefinitionStep $step,
        array $approverOverrides,
        ?string $orgId,
        ?Closure $userResolver = null,
    ): void {
        $stepOrder = $step->step_order;
        $definitionStepId = $step->exists ? $step->id : null;
        $deadlineAt = $step->calculateDeadlineAt();

        // Resolve the list of users that must approve this parallel step
        $users = ($step->approver_role && $userResolver !== null)
            ? $userResolver($step->approver_role, $orgId)
            : collect();

        if ($users->isNotEmpty()) {
            foreach ($users as $user) {
                WorkflowStepApproval::create([
                    'workflow_instance_id' => $instance->id,
                    'workflow_definition_step_id' => $definitionStepId,
                    'step_order' => $stepOrder,
                    'approver_id' => (string) $user->getKey(),
                    'approver_role' => $step->approver_role,
                    'status' => WorkflowStepApprovalStatus::Pending->value,
                    'deadline_at' => $deadlineAt,
                ]);
            }

            return;
        }

        // Fallback: no users resolved — create a single row to avoid blocking the workflow
        $overrideKey = "step_{$stepOrder}";
        $specificApproverId = $approverOverrides[$overrideKey] ?? null;

        WorkflowStepApproval::create([
            'workflow_instance_id' => $instance->id,
            'workflow_definition_step_id' => $definitionStepId,
            'step_order' => $stepOrder,
            'approver_id' => $specificApproverId,
            'approver_role' => $step->approver_role,
            'status' => WorkflowStepApprovalStatus::Pending->value,
            'deadline_at' => $deadlineAt,
        ]);
    }

    /**
     * Check whether the current step is complete.
     *
     * Parallel completion is enforced by the N pending rows created at step start:
     * all must be approved before pending count reaches zero. The logic is
     * identical to SerialStepHandler — the difference is how many rows exist.
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

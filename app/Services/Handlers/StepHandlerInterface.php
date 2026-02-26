<?php

declare(strict_types=1);

namespace Omnify\Workflow\Services\Handlers;

use Closure;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowInstance;

/**
 * Contract for step handler implementations.
 *
 * Each step type (serial, parallel) has its own handler that encapsulates
 * approval creation and completion check logic for that type.
 *
 * Handlers are stateless — they receive all necessary data via parameters.
 */
interface StepHandlerInterface
{
    /**
     * Create WorkflowStepApproval rows for the given step.
     *
     * @param  WorkflowInstance  $instance  The active workflow instance
     * @param  WorkflowDefinitionStep  $step  The step definition to create approvals for
     * @param  array  $approverOverrides  Map of ['step_N' => userId] for specific overrides
     * @param  string|null  $orgId  Organization context for role resolution
     * @param  Closure|null  $userResolver  Closure(string $role, ?string $orgId): Collection — resolves users by role (required for parallel handlers)
     */
    public function createApprovals(
        WorkflowInstance $instance,
        WorkflowDefinitionStep $step,
        array $approverOverrides,
        ?string $orgId,
        ?Closure $userResolver = null,
    ): void;

    /**
     * Check whether the current step on the instance is complete.
     *
     * A step is complete when at least one approval row is Approved
     * and no rows remain in Pending status.
     *
     * @param  WorkflowInstance  $instance  The active workflow instance
     */
    public function isComplete(WorkflowInstance $instance): bool;
}

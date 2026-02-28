<?php

namespace Omnify\Workflow\Database\Factories;

use Omnify\Workflow\Models\WorkflowStepApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Models\WorkflowDefinitionStep;

/**
 * WorkflowStepApproval Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<WorkflowStepApproval>
 */
class WorkflowStepApprovalFactory extends Factory
{
    protected $model = WorkflowStepApproval::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_instance_id' => WorkflowInstance::factory(),
            'workflow_definition_step_id' => WorkflowDefinitionStep::factory(),
            'step_order' => fake()->numberBetween(1, 10),
            'approver_id' => (string) \Illuminate\Support\Str::uuid(),
            'approver_role' => 'manager',
            'status' => 'pending',
            'comment' => null,
            'decided_at' => null,
            'deadline_at' => null,
            'notified_at' => null,
        ];
    }
}

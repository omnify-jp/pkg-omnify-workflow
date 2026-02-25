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
            'workflow_instance_id' => (string) \Illuminate\Support\Str::uuid(),
            'workflow_definition_step_id' => (string) \Illuminate\Support\Str::uuid(),
            'step_order' => fake()->numberBetween(1, 100),
            'approver_id' => (string) \Illuminate\Support\Str::uuid(),
            'approver_role' => fake()->sentence(),
            'status' => fake()->words(3, true),
            'comment' => fake()->paragraphs(3, true),
            'decided_at' => fake()->dateTime(),
            'deadline_at' => fake()->dateTime(),
            'notified_at' => fake()->dateTime(),
            'instance_id' => WorkflowInstance::query()->inRandomOrder()->first()?->id ?? WorkflowInstance::factory()->create()->id,
            'definition_step_id' => WorkflowDefinitionStep::query()->inRandomOrder()->first()?->id ?? WorkflowDefinitionStep::factory()->create()->id,
        ];
    }
}

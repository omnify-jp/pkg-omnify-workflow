<?php

declare(strict_types=1);

namespace Omnify\Workflow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Omnify\Workflow\Enums\WorkflowStepType;
use Omnify\Workflow\Models\WorkflowDefinitionStep;

class WorkflowDefinitionStepFactory extends Factory
{
    protected $model = WorkflowDefinitionStep::class;

    public function definition(): array
    {
        return [
            'workflow_definition_id' => \Omnify\Workflow\Models\WorkflowDefinition::factory(),
            'step_order' => 1,
            'name' => 'Phê duyệt',
            'description' => null,
            'approver_role' => 'manager',
            'type' => WorkflowStepType::Sequential,
            'deadline_hours' => 24,
            'escalate_to_role' => 'admin',
        ];
    }

    /** Step song song — yêu cầu tất cả approvers phê duyệt. */
    public function parallel(): static
    {
        return $this->state([
            'type' => WorkflowStepType::Parallel,
            'deadline_hours' => 72,
            'escalate_to_role' => null,
        ]);
    }

    /** Step không có deadline. */
    public function withoutDeadline(): static
    {
        return $this->state([
            'deadline_hours' => null,
            'escalate_to_role' => null,
        ]);
    }
}

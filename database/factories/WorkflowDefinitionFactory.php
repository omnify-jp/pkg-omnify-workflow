<?php

declare(strict_types=1);

namespace Omnify\Workflow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Omnify\Workflow\Models\WorkflowDefinition;

class WorkflowDefinitionFactory extends Factory
{
    protected $model = WorkflowDefinition::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'console_organization_id' => null,
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'config' => null,
        ];
    }

    /** Tạo definition với 2 steps mặc định (sequential). */
    public function withDefaultSteps(): static
    {
        return $this->afterCreating(function (WorkflowDefinition $definition) {
            \Omnify\Workflow\Models\WorkflowDefinitionStep::factory()->create([
                'workflow_definition_id' => $definition->id,
                'step_order' => 1,
                'name' => 'Quản lý duyệt',
                'approver_role' => 'manager',
                'type' => 'sequential',
                'deadline_hours' => 24,
                'escalate_to_role' => 'admin',
            ]);

            \Omnify\Workflow\Models\WorkflowDefinitionStep::factory()->create([
                'workflow_definition_id' => $definition->id,
                'step_order' => 2,
                'name' => 'Giám đốc duyệt',
                'approver_role' => 'admin',
                'type' => 'sequential',
                'deadline_hours' => 48,
                'escalate_to_role' => null,
            ]);
        });
    }

    /** Scope theo tổ chức cụ thể. */
    public function forOrganization(string $orgId): static
    {
        return $this->state(['console_organization_id' => $orgId]);
    }

    /** Definition bị tắt. */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

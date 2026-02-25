<?php

namespace Omnify\Workflow\Database\Factories;

use Omnify\Workflow\Models\WorkflowHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

use Omnify\Workflow\Models\WorkflowInstance;

/**
 * WorkflowHistory Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<WorkflowHistory>
 */
class WorkflowHistoryFactory extends Factory
{
    protected $model = WorkflowHistory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_instance_id' => (string) \Illuminate\Support\Str::uuid(),
            'actor_id' => (string) \Illuminate\Support\Str::uuid(),
            'action' => fake()->words(3, true),
            'from_status' => fake()->words(3, true),
            'to_status' => fake()->words(3, true),
            'step_order' => fake()->numberBetween(1, 100),
            'comment' => fake()->paragraphs(3, true),
            'metadata' => [],
            'instance_id' => WorkflowInstance::query()->inRandomOrder()->first()?->id ?? WorkflowInstance::factory()->create()->id,
        ];
    }
}

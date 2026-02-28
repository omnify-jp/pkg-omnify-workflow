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
            'workflow_instance_id' => WorkflowInstance::factory(),
            'actor_id' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'submitted',
            'from_status' => null,
            'to_status' => 'pending',
            'step_order' => null,
            'comment' => null,
            'metadata' => [],
            'created_at' => now(),
        ];
    }
}

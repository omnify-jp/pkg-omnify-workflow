<?php

declare(strict_types=1);

namespace Omnify\Workflow\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Omnify\Workflow\Enums\WorkflowStatus;
use Omnify\Workflow\Models\WorkflowInstance;

class WorkflowInstanceFactory extends Factory
{
    protected $model = WorkflowInstance::class;

    public function definition(): array
    {
        return [
            'workflow_definition_id' => \Omnify\Workflow\Models\WorkflowDefinition::factory(),
            'workflowable_type' => 'App\Models\LeaveRequest',
            'workflowable_id' => $this->faker->uuid(),
            'status' => WorkflowStatus::Pending,
            'current_step' => 1,
            'submitted_by' => $this->faker->uuid(),
            'submitted_at' => now(),
            'completed_at' => null,
            'metadata' => null,
        ];
    }

    /** Instance đã được phê duyệt. */
    public function approved(): static
    {
        return $this->state([
            'status' => WorkflowStatus::Approved,
            'completed_at' => now(),
        ]);
    }

    /** Instance đã bị từ chối. */
    public function rejected(): static
    {
        return $this->state([
            'status' => WorkflowStatus::Rejected,
            'completed_at' => now(),
        ]);
    }

    /** Instance đã bị hủy. */
    public function cancelled(): static
    {
        return $this->state([
            'status' => WorkflowStatus::Cancelled,
            'completed_at' => now(),
        ]);
    }
}

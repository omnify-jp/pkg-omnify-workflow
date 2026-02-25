<?php

declare(strict_types=1);

use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowInstance;

function validDefinitionData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Leave Approval',
        'slug' => 'leave-approval',
        'description' => 'Approval flow for leave requests',
        'is_active' => true,
        'steps' => [
            [
                'name' => 'Manager Approval',
                'approver_role' => 'manager',
                'type' => 'sequential',
                'deadline_hours' => 24,
                'escalate_to_role' => 'admin',
            ],
        ],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

it('creates a definition with steps', function () {
    $user = createUser('admin');

    $this->actingAs($user)
        ->post('/api/workflow/definitions', validDefinitionData())
        ->assertRedirect();

    $definition = WorkflowDefinition::where('slug', 'leave-approval')->first();
    expect($definition)->not->toBeNull();
    expect($definition->name)->toBe('Leave Approval');
    expect($definition->is_active)->toBeTrue();
    expect($definition->steps)->toHaveCount(1);
    expect($definition->steps->first()->name)->toBe('Manager Approval');
    expect($definition->steps->first()->approver_role)->toBe('manager');
    expect($definition->steps->first()->step_order)->toBe(1);
});

it('creates a definition with multiple steps in correct order', function () {
    $user = createUser('admin');

    $data = validDefinitionData([
        'steps' => [
            ['name' => 'Step A', 'approver_role' => 'manager', 'type' => 'sequential', 'deadline_hours' => null, 'escalate_to_role' => null],
            ['name' => 'Step B', 'approver_role' => 'admin', 'type' => 'parallel', 'deadline_hours' => 48, 'escalate_to_role' => null],
        ],
    ]);

    $this->actingAs($user)
        ->post('/api/workflow/definitions', $data)
        ->assertRedirect();

    $steps = WorkflowDefinition::where('slug', 'leave-approval')->first()->steps;
    expect($steps)->toHaveCount(2);
    expect($steps[0]->step_order)->toBe(1);
    expect($steps[0]->name)->toBe('Step A');
    expect($steps[1]->step_order)->toBe(2);
    expect($steps[1]->name)->toBe('Step B');
    expect($steps[1]->type->value)->toBe('parallel');
});

it('validates required fields on store', function () {
    $user = createUser('admin');

    $this->actingAs($user)
        ->post('/api/workflow/definitions', [])
        ->assertSessionHasErrors(['name', 'slug', 'is_active', 'steps']);
});

it('validates slug format', function () {
    $user = createUser('admin');

    $data = validDefinitionData(['slug' => 'Invalid Slug!']);

    $this->actingAs($user)
        ->post('/api/workflow/definitions', $data)
        ->assertSessionHasErrors('slug');
});

it('validates slug uniqueness per organization', function () {
    $user = createUser('admin', ['console_organization_id' => 'org-1']);

    WorkflowDefinition::factory()->create([
        'slug' => 'leave-approval',
        'console_organization_id' => 'org-1',
    ]);

    $this->actingAs($user)
        ->post('/api/workflow/definitions', validDefinitionData())
        ->assertSessionHasErrors('slug');
});

it('validates at least one step is required', function () {
    $user = createUser('admin');

    $data = validDefinitionData(['steps' => []]);

    $this->actingAs($user)
        ->post('/api/workflow/definitions', $data)
        ->assertSessionHasErrors('steps');
});

it('validates step type values', function () {
    $user = createUser('admin');

    $data = validDefinitionData([
        'steps' => [
            ['name' => 'Step', 'approver_role' => 'manager', 'type' => 'invalid_type', 'deadline_hours' => null, 'escalate_to_role' => null],
        ],
    ]);

    $this->actingAs($user)
        ->post('/api/workflow/definitions', $data)
        ->assertSessionHasErrors('steps.0.type');
});

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------

it('updates a definition and replaces steps', function () {
    $user = createUser('admin', ['console_organization_id' => 'org-1']);
    $definition = WorkflowDefinition::factory()->create([
        'console_organization_id' => 'org-1',
    ]);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'Old Step',
    ]);

    $data = [
        'name' => 'Updated Flow',
        'slug' => $definition->slug,
        'description' => 'Updated description',
        'is_active' => false,
        'steps' => [
            ['name' => 'New Step 1', 'approver_role' => 'admin', 'type' => 'parallel', 'deadline_hours' => 72, 'escalate_to_role' => null],
            ['name' => 'New Step 2', 'approver_role' => 'ceo', 'type' => 'sequential', 'deadline_hours' => null, 'escalate_to_role' => null],
        ],
    ];

    $this->actingAs($user)
        ->put("/api/workflow/definitions/{$definition->id}", $data)
        ->assertRedirect();

    $definition->refresh();
    expect($definition->name)->toBe('Updated Flow');
    expect($definition->is_active)->toBeFalse();

    $steps = $definition->steps()->orderBy('step_order')->get();
    expect($steps)->toHaveCount(2);
    expect($steps[0]->name)->toBe('New Step 1');
    expect($steps[0]->type->value)->toBe('parallel');
    expect($steps[1]->name)->toBe('New Step 2');
    expect($steps[1]->step_order)->toBe(2);

    // Old step should be deleted
    expect(WorkflowDefinitionStep::where('name', 'Old Step')->exists())->toBeFalse();
});

it('allows same slug when updating the same definition', function () {
    $user = createUser('admin', ['console_organization_id' => 'org-1']);
    $definition = WorkflowDefinition::factory()->create([
        'slug' => 'my-flow',
        'console_organization_id' => 'org-1',
    ]);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
    ]);

    $data = validDefinitionData(['slug' => 'my-flow']);

    $this->actingAs($user)
        ->put("/api/workflow/definitions/{$definition->id}", $data)
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
});

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

it('deletes a definition without pending instances', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
    ]);

    $this->actingAs($user)
        ->delete("/api/workflow/definitions/{$definition->id}")
        ->assertRedirect();

    expect(WorkflowDefinition::find($definition->id))->toBeNull();
    expect(WorkflowDefinitionStep::where('workflow_definition_id', $definition->id)->exists())->toBeFalse();
});

it('blocks deletion when pending instances exist', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->delete("/api/workflow/definitions/{$definition->id}")
        ->assertRedirect()
        ->assertSessionHasErrors('definition');

    expect(WorkflowDefinition::find($definition->id))->not->toBeNull();
});

it('allows deletion when only completed instances exist', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->delete("/api/workflow/definitions/{$definition->id}")
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(WorkflowDefinition::find($definition->id))->toBeNull();
});

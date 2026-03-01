<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowInstance;

function validAdminDefinitionData(array $overrides = []): array
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
// Index (Inertia page)
// ---------------------------------------------------------------------------

it('returns definitions index page', function () {
    $user = createUser('admin');
    WorkflowDefinition::factory()->count(3)->create();

    $this->actingAs($user)
        ->get('/settings/workflow/definitions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/workflow/definitions/index')
            ->has('definitions.data', 3)
            ->has('definitions.meta')
            ->has('filters')
        );
});

it('supports search filter on definitions index', function () {
    $user = createUser('admin');
    WorkflowDefinition::factory()->create(['name' => 'Leave Approval', 'slug' => 'leave-approval']);
    WorkflowDefinition::factory()->create(['name' => 'Purchase Order', 'slug' => 'purchase-order']);

    $this->actingAs($user)
        ->get('/settings/workflow/definitions?q=leave')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('definitions.data', 1)
            ->where('definitions.data.0.name', 'Leave Approval')
        );
});

it('supports is_active filter on definitions index', function () {
    $user = createUser('admin');
    WorkflowDefinition::factory()->create(['is_active' => true]);
    WorkflowDefinition::factory()->create(['is_active' => true]);
    WorkflowDefinition::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->get('/settings/workflow/definitions?filter[is_active]=1')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('definitions.data', 2)
        );
});

it('supports sorting on definitions index', function () {
    $user = createUser('admin');
    WorkflowDefinition::factory()->create(['name' => 'Beta Flow']);
    WorkflowDefinition::factory()->create(['name' => 'Alpha Flow']);

    $this->actingAs($user)
        ->get('/settings/workflow/definitions?sort=name')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('definitions.data.0.name', 'Alpha Flow')
            ->where('definitions.data.1.name', 'Beta Flow')
        );
});

it('supports descending sort on definitions index', function () {
    $user = createUser('admin');
    WorkflowDefinition::factory()->create(['name' => 'Alpha Flow']);
    WorkflowDefinition::factory()->create(['name' => 'Beta Flow']);

    $this->actingAs($user)
        ->get('/settings/workflow/definitions?sort=-name')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('definitions.data.0.name', 'Beta Flow')
            ->where('definitions.data.1.name', 'Alpha Flow')
        );
});

it('passes filters back in response', function () {
    $user = createUser('admin');

    $this->actingAs($user)
        ->get('/settings/workflow/definitions?q=test&sort=-name')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.q', 'test')
            ->where('filters.sort', '-name')
        );
});

it('includes steps_count in definitions', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();
    foreach (range(1, 3) as $order) {
        WorkflowDefinitionStep::factory()->create([
            'workflow_definition_id' => $definition->id,
            'step_order' => $order,
        ]);
    }

    $this->actingAs($user)
        ->get('/settings/workflow/definitions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('definitions.data.0.steps_count', 3)
        );
});

// ---------------------------------------------------------------------------
// Store (JSON API)
// ---------------------------------------------------------------------------

it('creates a definition via admin store', function () {
    $user = createUser('admin');

    $response = $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', validAdminDefinitionData());

    $response->assertStatus(201)
        ->assertJsonStructure(['message', 'id']);

    $definition = WorkflowDefinition::where('slug', 'leave-approval')->first();
    expect($definition)->not->toBeNull();
    expect($definition->name)->toBe('Leave Approval');
    expect($definition->is_active)->toBeTrue();
    expect($definition->steps)->toHaveCount(1);
    expect($definition->steps->first()->name)->toBe('Manager Approval');
    expect($definition->steps->first()->step_order)->toBe(1);
});

it('creates a definition with multiple steps', function () {
    $user = createUser('admin');

    $data = validAdminDefinitionData([
        'steps' => [
            ['name' => 'Step A', 'approver_role' => 'manager', 'type' => 'sequential', 'deadline_hours' => null, 'escalate_to_role' => null],
            ['name' => 'Step B', 'approver_role' => 'admin', 'type' => 'parallel', 'deadline_hours' => 48, 'escalate_to_role' => null],
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(201);

    $steps = WorkflowDefinition::where('slug', 'leave-approval')->first()->steps;
    expect($steps)->toHaveCount(2);
    expect($steps[0]->step_order)->toBe(1);
    expect($steps[0]->name)->toBe('Step A');
    expect($steps[1]->step_order)->toBe(2);
    expect($steps[1]->type->value)->toBe('parallel');
});

it('validates required fields on admin store', function () {
    $user = createUser('admin');

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'slug', 'is_active', 'steps']);
});

it('validates slug format on admin store', function () {
    $user = createUser('admin');

    $data = validAdminDefinitionData(['slug' => 'Invalid Slug!']);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('validates slug uniqueness on admin store', function () {
    $user = createUser('admin', ['console_organization_id' => 'org-1']);
    WorkflowDefinition::factory()->create([
        'slug' => 'leave-approval',
        'console_organization_id' => 'org-1',
    ]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', validAdminDefinitionData())
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('validates at least one step on admin store', function () {
    $user = createUser('admin');

    $data = validAdminDefinitionData(['steps' => []]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors('steps');
});

it('validates step type values on admin store', function () {
    $user = createUser('admin');

    $data = validAdminDefinitionData([
        'steps' => [
            ['name' => 'Step', 'approver_role' => 'manager', 'type' => 'invalid_type', 'deadline_hours' => null, 'escalate_to_role' => null],
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors('steps.0.type');
});

// ---------------------------------------------------------------------------
// Update (JSON API)
// ---------------------------------------------------------------------------

it('updates a definition and replaces steps via admin', function () {
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
        ->putJson("/settings/workflow/definitions/{$definition->id}", $data)
        ->assertOk()
        ->assertJson(['message' => 'Workflow definition updated.']);

    $definition->refresh();
    expect($definition->name)->toBe('Updated Flow');
    expect($definition->is_active)->toBeFalse();

    $steps = $definition->steps()->orderBy('step_order')->get();
    expect($steps)->toHaveCount(2);
    expect($steps[0]->name)->toBe('New Step 1');
    expect($steps[0]->type->value)->toBe('parallel');
    expect($steps[1]->name)->toBe('New Step 2');
    expect($steps[1]->step_order)->toBe(2);

    expect(WorkflowDefinitionStep::where('name', 'Old Step')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Destroy (JSON API)
// ---------------------------------------------------------------------------

it('deletes a definition without pending instances via admin', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
    ]);

    $this->actingAs($user)
        ->deleteJson("/settings/workflow/definitions/{$definition->id}")
        ->assertOk()
        ->assertJson(['message' => 'Workflow definition deleted.']);

    expect(WorkflowDefinition::find($definition->id))->toBeNull();
});

it('blocks deletion when pending instances exist via admin', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->deleteJson("/settings/workflow/definitions/{$definition->id}")
        ->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete: this definition has active workflow instances.']);

    expect(WorkflowDefinition::find($definition->id))->not->toBeNull();
});

it('allows deletion when only completed instances exist via admin', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->deleteJson("/settings/workflow/definitions/{$definition->id}")
        ->assertOk();

    expect(WorkflowDefinition::find($definition->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// approver_user_ids — Definition CRUD
// ---------------------------------------------------------------------------

it('index page includes users in Inertia props', function () {
    $user = createUser('admin');

    $this->actingAs($user)
        ->get('/settings/workflow/definitions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users')
            ->has('roles')
        );
});

it('creates a definition with approver_user_ids on steps', function () {
    $user = createUser('admin');
    $approver1 = createUser('manager');
    $approver2 = createUser('manager');

    $data = validAdminDefinitionData([
        'slug' => 'user-based-flow',
        'steps' => [
            [
                'name' => 'Specific Approvers',
                'approver_role' => null,
                'approver_user_ids' => [$approver1->id, $approver2->id],
                'type' => 'sequential',
                'deadline_hours' => 24,
                'escalate_to_role' => null,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(201);

    $definition = WorkflowDefinition::where('slug', 'user-based-flow')->first();
    expect($definition)->not->toBeNull();

    $step = $definition->steps->first();
    expect($step->approver_user_ids)->toBeArray();
    expect($step->approver_user_ids)->toHaveCount(2);
    expect($step->approver_user_ids)->toContain($approver1->id, $approver2->id);
    expect($step->approver_role)->toBeNull();
});

it('creates a definition with mixed role and user steps', function () {
    $user = createUser('admin');
    $approver = createUser('staff');

    $data = validAdminDefinitionData([
        'slug' => 'mixed-flow',
        'steps' => [
            [
                'name' => 'Role-based Step',
                'approver_role' => 'manager',
                'approver_user_ids' => null,
                'type' => 'sequential',
                'deadline_hours' => null,
                'escalate_to_role' => null,
            ],
            [
                'name' => 'User-based Step',
                'approver_role' => null,
                'approver_user_ids' => [$approver->id],
                'type' => 'parallel',
                'deadline_hours' => 48,
                'escalate_to_role' => null,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(201);

    $steps = WorkflowDefinition::where('slug', 'mixed-flow')->first()->steps;
    expect($steps)->toHaveCount(2);
    expect($steps[0]->approver_role)->toBe('manager');
    expect($steps[0]->approver_user_ids)->toBeNull();
    expect($steps[1]->approver_role)->toBeNull();
    expect($steps[1]->approver_user_ids)->toBe([$approver->id]);
});

it('updates a definition and preserves approver_user_ids', function () {
    $user = createUser('admin', ['console_organization_id' => 'org-1']);
    $approver = createUser('staff');

    $definition = WorkflowDefinition::factory()->create([
        'console_organization_id' => 'org-1',
    ]);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'Old Step',
    ]);

    $data = [
        'name' => $definition->name,
        'slug' => $definition->slug,
        'description' => $definition->description,
        'is_active' => true,
        'steps' => [
            [
                'name' => 'Updated Step',
                'approver_role' => null,
                'approver_user_ids' => [$approver->id],
                'type' => 'sequential',
                'deadline_hours' => null,
                'escalate_to_role' => null,
            ],
        ],
    ];

    $this->actingAs($user)
        ->putJson("/settings/workflow/definitions/{$definition->id}", $data)
        ->assertOk();

    $step = $definition->fresh()->steps->first();
    expect($step->name)->toBe('Updated Step');
    expect($step->approver_user_ids)->toBe([$approver->id]);
});

it('stores empty approver_user_ids as null', function () {
    $user = createUser('admin');

    $data = validAdminDefinitionData([
        'slug' => 'null-ids-flow',
        'steps' => [
            [
                'name' => 'Step',
                'approver_role' => 'manager',
                'approver_user_ids' => [],
                'type' => 'sequential',
                'deadline_hours' => null,
                'escalate_to_role' => null,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(201);

    $step = WorkflowDefinition::where('slug', 'null-ids-flow')->first()->steps->first();
    expect($step->approver_user_ids)->toBeNull();
    expect($step->approver_role)->toBe('manager');
});

it('validates approver_user_ids must contain valid UUIDs', function () {
    $user = createUser('admin');

    $data = validAdminDefinitionData([
        'steps' => [
            [
                'name' => 'Step',
                'approver_role' => null,
                'approver_user_ids' => ['not-a-uuid', '12345'],
                'type' => 'sequential',
                'deadline_hours' => null,
                'escalate_to_role' => null,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->postJson('/settings/workflow/definitions', $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['steps.0.approver_user_ids.0', 'steps.0.approver_user_ids.1']);
});

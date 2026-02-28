<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowHistory;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Models\WorkflowStepApproval;

// ---------------------------------------------------------------------------
// Index (Inertia page)
// ---------------------------------------------------------------------------

it('returns instances index page', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();
    WorkflowInstance::factory()->count(3)->create([
        'workflow_definition_id' => $definition->id,
    ]);

    $this->actingAs($user)
        ->get('/admin/workflow/instances')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/workflow/instances/index')
            ->has('instances.data', 3)
            ->has('instances.meta')
            ->has('definitions')
            ->has('filters')
        );
});

it('supports status filter on instances index', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();
    WorkflowInstance::factory()->count(2)->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'pending',
    ]);
    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get('/admin/workflow/instances?filter[status]=pending')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('instances.data', 2)
        );
});

it('supports definition_id filter on instances index', function () {
    $user = createUser('admin');
    $defA = WorkflowDefinition::factory()->create();
    $defB = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->count(2)->create([
        'workflow_definition_id' => $defA->id,
    ]);
    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $defB->id,
    ]);

    $this->actingAs($user)
        ->get("/admin/workflow/instances?filter[definition_id]={$defA->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('instances.data', 2)
        );
});

it('supports search by workflowable_type on instances index', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'workflowable_type' => 'App\Models\LeaveRequest',
    ]);
    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'workflowable_type' => 'App\Models\PurchaseOrder',
    ]);

    $this->actingAs($user)
        ->get('/admin/workflow/instances?q=LeaveRequest')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('instances.data', 1)
        );
});

it('supports date range filter on instances index', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'submitted_at' => '2025-01-15 10:00:00',
    ]);
    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'submitted_at' => '2025-03-20 10:00:00',
    ]);

    $this->actingAs($user)
        ->get('/admin/workflow/instances?filter[submitted_at_from]=2025-01-01&filter[submitted_at_to]=2025-02-01')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('instances.data', 1)
        );
});

it('supports sorting on instances index', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();

    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'approved',
        'submitted_at' => '2025-01-10',
    ]);
    WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
        'status' => 'pending',
        'submitted_at' => '2025-01-20',
    ]);

    // Default sort: -submitted_at (desc)
    $this->actingAs($user)
        ->get('/admin/workflow/instances')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('instances.data.0.submitted_at', fn ($value) => str_contains($value, '2025-01-20'))
        );
});

it('returns definitions list for filter dropdown', function () {
    $user = createUser('admin');
    WorkflowDefinition::factory()->count(2)->create();

    $this->actingAs($user)
        ->get('/admin/workflow/instances')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('definitions', 2)
        );
});

// ---------------------------------------------------------------------------
// Show (Inertia page)
// ---------------------------------------------------------------------------

it('returns instance show page with relations', function () {
    $user = createUser('admin');
    $definition = WorkflowDefinition::factory()->create();
    $step = WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
    ]);

    $instance = WorkflowInstance::factory()->create([
        'workflow_definition_id' => $definition->id,
    ]);

    WorkflowStepApproval::factory()->create([
        'workflow_instance_id' => $instance->id,
        'workflow_definition_step_id' => $step->id,
        'step_order' => 1,
        'status' => 'pending',
    ]);

    WorkflowHistory::factory()->create([
        'workflow_instance_id' => $instance->id,
        'action' => 'submitted',
    ]);

    $this->actingAs($user)
        ->get("/admin/workflow/instances/{$instance->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/workflow/instances/show')
            ->has('instance')
            ->has('instance.definition')
            ->has('instance.step_approvals')
            ->has('instance.history')
        );
});

it('returns 404 for non-existent instance', function () {
    $user = createUser('admin');

    $this->actingAs($user)
        ->get('/admin/workflow/instances/non-existent-uuid')
        ->assertNotFound();
});

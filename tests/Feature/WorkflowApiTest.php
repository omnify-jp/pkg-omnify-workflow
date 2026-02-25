<?php

declare(strict_types=1);

use Omnify\Workflow\Enums\WorkflowStatus;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Services\WorkflowEngine;
use Omnify\Workflow\Tests\Fixtures\Models\FakeLeaveRequest;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function makeApiDefinition(string $slug = 'leave-approval'): WorkflowDefinition
{
    $definition = WorkflowDefinition::factory()->create(['slug' => $slug]);

    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'Manager',
        'approver_role' => 'manager',
        'type' => 'sequential',
        'deadline_hours' => 24,
        'escalate_to_role' => null,
    ]);

    return $definition;
}

// ---------------------------------------------------------------------------
// GET /api/workflow/instances/{id}
// ---------------------------------------------------------------------------

it('show returns 404 for non-existent instance', function () {
    $user = createUser('manager');

    $this->actingAs($user)
        ->getJson('/api/workflow/instances/00000000-0000-0000-0000-000000000000')
        ->assertNotFound();
});

it('show returns instance with history and approvals', function () {
    makeApiDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($manager)
        ->getJson("/api/workflow/instances/{$instance->id}")
        ->assertSuccessful()
        ->assertJsonStructure([
            'instance' => ['id', 'status', 'current_step'],
            'history',
            'approvals',
        ]);
});

// ---------------------------------------------------------------------------
// POST /api/workflow/instances/{id}/approve
// ---------------------------------------------------------------------------

it('approve endpoint marks workflow approved', function () {
    makeApiDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($manager)
        ->postJson("/api/workflow/instances/{$instance->id}/approve", [
            'comment' => 'Trông OK',
        ])
        ->assertSuccessful()
        ->assertJsonFragment(['message' => 'Đã phê duyệt thành công.']);

    expect(WorkflowInstance::find($instance->id)->status)->toBe(WorkflowStatus::Approved);
});

it('approve endpoint returns 422 for wrong role', function () {
    makeApiDefinition('leave-approval');
    $staff = createUser('staff');   // sai role
    $submitter = createUser('submitter');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($staff)
        ->postJson("/api/workflow/instances/{$instance->id}/approve")
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// POST /api/workflow/instances/{id}/reject
// ---------------------------------------------------------------------------

it('reject endpoint requires comment field', function () {
    makeApiDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($manager)
        ->postJson("/api/workflow/instances/{$instance->id}/reject", [])
        ->assertUnprocessable();
});

it('reject endpoint marks workflow rejected', function () {
    makeApiDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($manager)
        ->postJson("/api/workflow/instances/{$instance->id}/reject", [
            'comment' => 'Không đủ ngân sách',
        ])
        ->assertSuccessful()
        ->assertJsonFragment(['message' => 'Đã từ chối.']);

    expect(WorkflowInstance::find($instance->id)->status)->toBe(WorkflowStatus::Rejected);
});

// ---------------------------------------------------------------------------
// POST /api/workflow/instances/{id}/cancel
// ---------------------------------------------------------------------------

it('cancel endpoint requires reason field', function () {
    makeApiDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($submitter)
        ->postJson("/api/workflow/instances/{$instance->id}/cancel", [])
        ->assertUnprocessable();
});

it('cancel endpoint marks workflow cancelled when called by submitter', function () {
    makeApiDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($submitter)
        ->postJson("/api/workflow/instances/{$instance->id}/cancel", [
            'reason' => 'Không cần nữa',
        ])
        ->assertSuccessful()
        ->assertJsonFragment(['message' => 'Đã hủy workflow.']);

    expect(WorkflowInstance::find($instance->id)->status)->toBe(WorkflowStatus::Cancelled);
});

it('cancel endpoint returns 403 when called by non-submitter non-admin', function () {
    makeApiDefinition('leave-approval');
    $submitter = createUser('staff');
    $otherUser = createUser('manager');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $this->actingAs($otherUser)
        ->postJson("/api/workflow/instances/{$instance->id}/cancel", [
            'reason' => 'Tôi muốn hủy của người khác',
        ])
        ->assertForbidden();
});

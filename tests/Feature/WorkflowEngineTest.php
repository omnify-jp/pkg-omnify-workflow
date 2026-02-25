<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Omnify\Workflow\Enums\WorkflowStatus;
use Omnify\Workflow\Enums\WorkflowStepApprovalStatus;
use Omnify\Workflow\Events\WorkflowApproved;
use Omnify\Workflow\Events\WorkflowCancelled;
use Omnify\Workflow\Events\WorkflowRejected;
use Omnify\Workflow\Events\WorkflowStepCompleted;
use Omnify\Workflow\Events\WorkflowSubmitted;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowHistory;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Models\WorkflowStepApproval;
use Omnify\Workflow\Services\WorkflowEngine;
use Omnify\Workflow\Tests\Fixtures\Models\FakeLeaveRequest;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeDefinition(string $slug = 'leave-approval', bool $withSteps = true): WorkflowDefinition
{
    $definition = WorkflowDefinition::factory()->create(['slug' => $slug]);

    if ($withSteps) {
        WorkflowDefinitionStep::factory()->create([
            'workflow_definition_id' => $definition->id,
            'step_order' => 1,
            'name' => 'Quản lý duyệt',
            'approver_role' => 'manager',
            'type' => 'sequential',
            'deadline_hours' => 24,
            'escalate_to_role' => 'admin',
        ]);
    }

    return $definition;
}

function makeTwoStepDefinition(string $slug = 'two-step'): WorkflowDefinition
{
    $definition = WorkflowDefinition::factory()->create(['slug' => $slug]);

    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'Quản lý',
        'approver_role' => 'manager',
        'type' => 'sequential',
        'deadline_hours' => 24,
        'escalate_to_role' => null,
    ]);

    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 2,
        'name' => 'Giám đốc',
        'approver_role' => 'admin',
        'type' => 'sequential',
        'deadline_hours' => 48,
        'escalate_to_role' => null,
    ]);

    return $definition;
}

// ---------------------------------------------------------------------------
// start()
// ---------------------------------------------------------------------------

it('creates instance and step approvals when started', function () {
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test Leave']);

    $engine = app(WorkflowEngine::class);
    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);

    expect($instance)->toBeInstanceOf(WorkflowInstance::class);
    expect($instance->status)->toBe(WorkflowStatus::Pending);
    expect($instance->current_step)->toBe(1);
    expect($instance->submitted_by)->toBe($submitter->id);

    // Step approval tạo ra cho step 1
    $approval = WorkflowStepApproval::where('workflow_instance_id', $instance->id)->first();
    expect($approval)->not->toBeNull();
    expect($approval->step_order)->toBe(1);
    expect($approval->approver_role)->toBe('manager');
    expect($approval->approver_id)->toBeNull();  // sequential: null
    expect($approval->status)->toBe(WorkflowStepApprovalStatus::Pending);
});

it('fires WorkflowSubmitted event on start', function () {
    Event::fake();
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    Event::assertDispatched(WorkflowSubmitted::class);
});

it('records submitted history on start', function () {
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    $history = WorkflowHistory::where('workflow_instance_id', $instance->id)
        ->where('action', WorkflowHistory::SUBMITTED)
        ->first();

    expect($history)->not->toBeNull();
    expect($history->actor_id)->toBe($submitter->id);
    expect($history->to_status)->toBe('pending');
});

it('stores metadata in instance', function () {
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter, metadata: [
        'reason' => 'Việc gia đình',
        'leave_days' => 3,
    ]);

    expect($instance->metadata['reason'])->toBe('Việc gia đình');
    expect($instance->metadata['leave_days'])->toBe(3);
});

it('throws exception if model already has active workflow', function () {
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $engine->start($leaveRequest, 'leave-approval', $submitter);

    // Start lần 2 phải throw
    expect(fn () => $engine->start($leaveRequest, 'leave-approval', $submitter))
        ->toThrow(RuntimeException::class);
});

it('throws exception if definition does not exist', function () {
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    expect(fn () => app(WorkflowEngine::class)->start($leaveRequest, 'nonexistent-slug', $submitter))
        ->toThrow(RuntimeException::class, "'nonexistent-slug'");
});

it('throws exception if definition is inactive', function () {
    WorkflowDefinition::factory()->inactive()->create(['slug' => 'inactive-flow']);
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    expect(fn () => app(WorkflowEngine::class)->start($leaveRequest, 'inactive-flow', $submitter))
        ->toThrow(RuntimeException::class);
});

it('throws exception if definition has no steps', function () {
    makeDefinition('empty-flow', withSteps: false);
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    expect(fn () => app(WorkflowEngine::class)->start($leaveRequest, 'empty-flow', $submitter))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// approve() — Single step
// ---------------------------------------------------------------------------

it('marks workflow approved when last step is approved', function () {
    makeDefinition('leave-approval');    // 1 step only
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->approve($instance, $manager, 'Trông OK');

    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Approved);
    expect($instance->completed_at)->not->toBeNull();
});

it('fires WorkflowApproved event after last step', function () {
    Event::fake();
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->approve($instance, $manager);

    Event::assertDispatched(WorkflowApproved::class);
});

it('sets approver_id on sequential approval row after approve', function () {
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->approve($instance, $manager, 'OK');

    $approval = WorkflowStepApproval::where('workflow_instance_id', $instance->id)->first();
    expect($approval->approver_id)->toBe($manager->id);
    expect($approval->status)->toBe(WorkflowStepApprovalStatus::Approved);
    expect($approval->comment)->toBe('OK');
    expect($approval->decided_at)->not->toBeNull();
});

it('throws exception when approving completed workflow', function () {
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->approve($instance, $manager);
    $instance->refresh();

    // Approve lần 2 phải throw
    expect(fn () => $engine->approve($instance, $manager))
        ->toThrow(RuntimeException::class);
});

it('throws exception when approver does not have required role', function () {
    makeDefinition('leave-approval');    // requires 'manager' role
    $staff = createUser('staff'); // sai role
    $submitter = createUser('submitter');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);

    expect(fn () => $engine->approve($instance, $staff))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// approve() — Two steps: advance to next step
// ---------------------------------------------------------------------------

it('advances to next step after first step approval', function () {
    makeTwoStepDefinition('two-step');
    $manager = createUser('manager');
    $admin = createUser('admin');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'two-step', $submitter);
    expect($instance->current_step)->toBe(1);

    $engine->approve($instance, $manager);
    $instance->refresh();

    // Sau khi approve step 1 → current_step = 2, status vẫn pending
    expect($instance->current_step)->toBe(2);
    expect($instance->status)->toBe(WorkflowStatus::Pending);

    // Approval rows cho step 2 đã được tạo
    $step2Approval = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->where('step_order', 2)
        ->where('status', WorkflowStepApprovalStatus::Pending->value)
        ->first();
    expect($step2Approval)->not->toBeNull();
    expect($step2Approval->approver_role)->toBe('admin');
});

it('fires WorkflowStepCompleted event when step advances', function () {
    Event::fake();
    makeTwoStepDefinition('two-step');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'two-step', $submitter);
    $engine->approve($instance, $manager);

    Event::assertDispatched(WorkflowStepCompleted::class, function ($event) use ($instance) {
        return $event->instance->id === $instance->id && $event->completedStep === 1;
    });
});

it('completes two-step workflow after both approvals', function () {
    makeTwoStepDefinition('two-step');
    $manager = createUser('manager');
    $admin = createUser('admin');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'two-step', $submitter);
    $engine->approve($instance, $manager);
    $instance->refresh();
    $engine->approve($instance, $admin);
    $instance->refresh();

    expect($instance->status)->toBe(WorkflowStatus::Approved);
    expect($instance->completed_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// reject()
// ---------------------------------------------------------------------------

it('marks workflow rejected immediately when reject is called', function () {
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->reject($instance, $manager, 'Không đủ ngân sách');
    $instance->refresh();

    expect($instance->status)->toBe(WorkflowStatus::Rejected);
    expect($instance->completed_at)->not->toBeNull();
});

it('fires WorkflowRejected event after reject', function () {
    Event::fake();
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->reject($instance, $manager, 'Không đồng ý');

    Event::assertDispatched(WorkflowRejected::class);
});

it('throws exception when reject comment is empty', function () {
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);

    expect(fn () => $engine->reject($instance, $manager, ''))
        ->toThrow(RuntimeException::class);
});

it('throws exception when rejecting completed workflow', function () {
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->approve($instance, $manager);
    $instance->refresh();

    expect(fn () => $engine->reject($instance, $manager, 'Quá muộn'))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// cancel()
// ---------------------------------------------------------------------------

it('marks workflow cancelled when cancel is called', function () {
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->cancel($instance, $submitter, 'Không cần nữa');
    $instance->refresh();

    expect($instance->status)->toBe(WorkflowStatus::Cancelled);
    expect($instance->completed_at)->not->toBeNull();
});

it('fires WorkflowCancelled event with correct data', function () {
    Event::fake();
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->cancel($instance, $submitter, 'Tôi đổi ý rồi');

    Event::assertDispatched(WorkflowCancelled::class, function ($event) {
        return $event->reason === 'Tôi đổi ý rồi';
    });
});

it('throws exception when cancelling completed workflow', function () {
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->approve($instance, $manager);
    $instance->refresh();

    expect(fn () => $engine->cancel($instance, $submitter, 'Muộn rồi'))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// Parallel approval
// ---------------------------------------------------------------------------

it('creates N approval rows for parallel step', function () {
    $definition = WorkflowDefinition::factory()->create(['slug' => 'board-approval']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'HĐQT',
        'approver_role' => 'board_member',
        'type' => 'parallel',
        'deadline_hours' => 72,
        'escalate_to_role' => null,
    ]);

    // Tạo 3 board members
    $b1 = createUser('board_member');
    $b2 = createUser('board_member');
    $b3 = createUser('board_member');

    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'board-approval', $submitter);

    $approvalCount = WorkflowStepApproval::where('workflow_instance_id', $instance->id)->count();
    expect($approvalCount)->toBe(3);

    // Mỗi row có approver_id cụ thể
    $ids = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->pluck('approver_id')
        ->toArray();
    expect($ids)->toContain($b1->id);
    expect($ids)->toContain($b2->id);
    expect($ids)->toContain($b3->id);
});

it('parallel step does not complete until all approve', function () {
    $definition = WorkflowDefinition::factory()->create(['slug' => 'board-approval']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'approver_role' => 'board_member',
        'type' => 'parallel',
        'deadline_hours' => null,
        'escalate_to_role' => null,
    ]);

    $b1 = createUser('board_member');
    $b2 = createUser('board_member');

    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'board-approval', $submitter);

    // B1 approve → vẫn pending (còn B2 chưa approve)
    $engine->approve($instance, $b1);
    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Pending);

    // B2 approve → hoàn thành
    $engine->approve($instance, $b2);
    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Approved);
});

it('parallel step rejects immediately if any member rejects', function () {
    $definition = WorkflowDefinition::factory()->create(['slug' => 'board-approval']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'approver_role' => 'board_member',
        'type' => 'parallel',
        'deadline_hours' => null,
        'escalate_to_role' => null,
    ]);

    $b1 = createUser('board_member');
    $b2 = createUser('board_member');

    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'board-approval', $submitter);
    $engine->approve($instance, $b1);
    $engine->reject($instance, $b2, 'Tôi phản đối');
    $instance->refresh();

    expect($instance->status)->toBe(WorkflowStatus::Rejected);
});

// ---------------------------------------------------------------------------
// SLA / Escalation
// ---------------------------------------------------------------------------

it('escalates overdue approval to escalate_to_role', function () {
    $definition = WorkflowDefinition::factory()->create(['slug' => 'sla-flow']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'approver_role' => 'manager',
        'type' => 'sequential',
        'deadline_hours' => 24,
        'escalate_to_role' => 'admin',
    ]);

    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'sla-flow', $submitter);

    // Giả lập quá hạn bằng cách set deadline_at về quá khứ
    WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->update(['deadline_at' => now()->subHours(1)]);

    $escalated = $engine->escalateExpired();
    expect($escalated)->toBe(1);

    // Row cũ → expired
    $expiredRow = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->where('status', WorkflowStepApprovalStatus::Expired->value)
        ->first();
    expect($expiredRow)->not->toBeNull();

    // Row mới → pending với escalate_to_role = admin
    $escalatedRow = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->where('status', WorkflowStepApprovalStatus::Pending->value)
        ->where('approver_role', 'admin')
        ->first();
    expect($escalatedRow)->not->toBeNull();
    // Deadline gấp đôi
    expect($escalatedRow->deadline_at)->not->toBeNull();
});

it('auto-approves when escalate_to_role is null', function () {
    $definition = WorkflowDefinition::factory()->create(['slug' => 'auto-approve-flow']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'approver_role' => 'manager',
        'type' => 'sequential',
        'deadline_hours' => 8,
        'escalate_to_role' => null,   // auto-approve
    ]);

    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'auto-approve-flow', $submitter);

    WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->update(['deadline_at' => now()->subHours(1)]);

    $engine->escalateExpired();

    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Approved);
});

it('escalateExpired skips already completed instances', function () {
    $definition = WorkflowDefinition::factory()->create(['slug' => 'completed-flow']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'approver_role' => 'manager',
        'type' => 'sequential',
        'deadline_hours' => 8,
        'escalate_to_role' => 'admin',
    ]);

    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'completed-flow', $submitter);

    // Approve trước
    $engine->approve($instance, $manager);

    // Set deadline quá hạn (nhưng instance đã completed)
    WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->update(['deadline_at' => now()->subHours(10)]);

    $escalated = $engine->escalateExpired();
    expect($escalated)->toBe(0);
});

// ---------------------------------------------------------------------------
// Config-based definition
// ---------------------------------------------------------------------------

it('resolves definition from config when not in DB', function () {
    // Không tạo DB record, chỉ set config
    config(['workflow.definitions.config-flow' => [
        'name' => 'Config Flow',
        'steps' => [
            [
                'name' => 'Manager Step',
                'approver_role' => 'manager',
                'type' => 'sequential',
                'deadline_hours' => 24,
                'escalate_to_role' => null,
            ],
        ],
    ]]);

    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'config-flow', $submitter);

    expect($instance->status)->toBe(WorkflowStatus::Pending);
    expect($instance->current_step)->toBe(1);
});

// ---------------------------------------------------------------------------
// HasWorkflow Trait
// ---------------------------------------------------------------------------

it('hasActiveWorkflow returns true when workflow is pending', function () {
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    app(WorkflowEngine::class)->start($leaveRequest, 'leave-approval', $submitter);

    expect($leaveRequest->hasActiveWorkflow())->toBeTrue();
    expect($leaveRequest->workflowStatus())->toBe(WorkflowStatus::Pending);
});

it('activeWorkflow returns null after workflow is approved', function () {
    makeDefinition('leave-approval');
    $manager = createUser('manager');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval', $submitter);
    $engine->approve($instance, $manager);

    expect($leaveRequest->activeWorkflow())->toBeNull();
    expect($leaveRequest->hasActiveWorkflow())->toBeFalse();
    expect($leaveRequest->workflowStatus())->toBe(WorkflowStatus::Approved);
});

it('startWorkflow via trait delegates to WorkflowEngine', function () {
    makeDefinition('leave-approval');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);

    $instance = $leaveRequest->startWorkflow('leave-approval', $submitter);

    expect($instance)->toBeInstanceOf(WorkflowInstance::class);
    expect($leaveRequest->hasActiveWorkflow())->toBeTrue();
});

// ---------------------------------------------------------------------------
// History tracking
// ---------------------------------------------------------------------------

it('records history entries throughout workflow lifecycle', function () {
    makeTwoStepDefinition('two-step');
    $manager = createUser('manager');
    $admin = createUser('admin');
    $submitter = createUser('staff');
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'two-step', $submitter);
    $engine->approve($instance, $manager, 'OK step 1');
    $instance->refresh();
    $engine->approve($instance, $admin, 'OK step 2');

    $history = WorkflowHistory::where('workflow_instance_id', $instance->id)
        ->orderBy('created_at')
        ->pluck('action')
        ->toArray();

    expect($history)->toContain(WorkflowHistory::SUBMITTED);
    expect($history)->toContain(WorkflowHistory::STEP_STARTED);
    expect($history)->toContain(WorkflowHistory::STEP_APPROVED);
    expect($history)->toContain(WorkflowHistory::STEP_COMPLETED);
    expect($history)->toContain(WorkflowHistory::APPROVED);
});

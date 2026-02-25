<?php

declare(strict_types=1);

use Omnify\Workflow\Enums\WorkflowStatus;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;
use Omnify\Workflow\Models\WorkflowStepApproval;
use Omnify\Workflow\Services\WorkflowEngine;
use Omnify\Workflow\Tests\Fixtures\Models\FakeLeaveRequest;

// ---------------------------------------------------------------------------
// Helpers (scoped cho file này)
// ---------------------------------------------------------------------------

/**
 * Tạo WorkflowDefinition với 1 step, cấu hình bằng params.
 */
function makeSsoDefinition(
    string $slug,
    string $approverRole = 'manager',
    string $type = 'sequential',
): void {
    $definition = WorkflowDefinition::factory()->create(['slug' => $slug]);

    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'Approval step',
        'approver_role' => $approverRole,
        'type' => $type,
        'deadline_hours' => 24,
        'escalate_to_role' => null,
    ]);
}

// ---------------------------------------------------------------------------
// userResolver — Parallel: tạo đúng N rows theo real SSO roles
// ---------------------------------------------------------------------------

it('userResolver creates one approval row per real SSO user with required role for parallel step', function () {
    // Parallel step yêu cầu role 'board_member'
    $definition = WorkflowDefinition::factory()->create(['slug' => 'board-approval']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'HĐQT duyệt',
        'approver_role' => 'board_member',
        'type' => 'parallel',
        'deadline_hours' => 72,
        'escalate_to_role' => null,
    ]);

    // 3 users có SSO role 'board_member' thật (qua roles() relationship + role_user pivot)
    $m1 = createUserWithRole('board_member');
    $m2 = createUserWithRole('board_member');
    $m3 = createUserWithRole('board_member');

    // 1 user KHÔNG có role này — không được tính vào approval rows
    $other = createUserWithoutRole();

    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Board Meeting']);

    $instance = app(WorkflowEngine::class)->start($leaveRequest, 'board-approval', $submitter);

    // Phải tạo đúng 3 rows — không tính 'other' hay 'submitter'
    expect(WorkflowStepApproval::where('workflow_instance_id', $instance->id)->count())->toBe(3);

    $approverIds = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
        ->pluck('approver_id')
        ->toArray();

    expect($approverIds)->toContain($m1->id);
    expect($approverIds)->toContain($m2->id);
    expect($approverIds)->toContain($m3->id);
    expect($approverIds)->not->toContain($other->id);
    expect($approverIds)->not->toContain($submitter->id);
});

// ---------------------------------------------------------------------------
// userResolver — Sequential: user với real SSO role có thể approve
// ---------------------------------------------------------------------------

it('real SSO user with required role can approve sequential step', function () {
    makeSsoDefinition('leave-approval-sso', 'manager', 'sequential');

    // Manager user: có SSO role 'manager' thật qua role_user pivot
    $manager = createUserWithRole('manager');
    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Leave request']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval-sso', $submitter);
    $engine->approve($instance, $manager, 'Đồng ý');

    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Approved);
});

// ---------------------------------------------------------------------------
// userResolver — Sequential: user KHÔNG có role bị từ chối
// ---------------------------------------------------------------------------

it('real SSO user without required role cannot approve sequential step', function () {
    makeSsoDefinition('leave-approval-sso', 'manager', 'sequential');

    // Staff user: có role 'staff' nhưng KHÔNG có 'manager'
    $staff = createUserWithRole('staff');
    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Leave request']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval-sso', $submitter);

    expect(fn () => $engine->approve($instance, $staff))
        ->toThrow(RuntimeException::class);
});

it('user with no roles at all cannot approve sequential step', function () {
    makeSsoDefinition('leave-approval-sso', 'manager', 'sequential');

    $noRoleUser = createUserWithoutRole();
    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Leave request']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval-sso', $submitter);

    expect(fn () => $engine->approve($instance, $noRoleUser))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// Parallel: tất cả SSO role holders phải approve
// ---------------------------------------------------------------------------

it('parallel workflow requires all real SSO role holders to approve before completing', function () {
    $definition = WorkflowDefinition::factory()->create(['slug' => 'director-approval']);
    WorkflowDefinitionStep::factory()->create([
        'workflow_definition_id' => $definition->id,
        'step_order' => 1,
        'name' => 'Giám đốc duyệt',
        'approver_role' => 'director',
        'type' => 'parallel',
        'deadline_hours' => 48,
        'escalate_to_role' => null,
    ]);

    $d1 = createUserWithRole('director');
    $d2 = createUserWithRole('director');

    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'director-approval', $submitter);

    // 2 approval rows — 1 per director
    expect(WorkflowStepApproval::where('workflow_instance_id', $instance->id)->count())->toBe(2);

    // D1 approve → instance vẫn Pending (D2 chưa approve)
    $engine->approve($instance, $d1);
    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Pending);

    // D2 approve → workflow hoàn thành
    $engine->approve($instance, $d2);
    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Approved);
});

// ---------------------------------------------------------------------------
// Reject: user với real SSO role có thể reject
// ---------------------------------------------------------------------------

it('real SSO user with required role can reject sequential step', function () {
    makeSsoDefinition('leave-approval-sso', 'manager', 'sequential');

    $manager = createUserWithRole('manager');
    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Leave request']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval-sso', $submitter);
    $engine->reject($instance, $manager, 'Không đủ ngân sách');

    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Rejected);
});

it('real SSO user without required role cannot reject sequential step', function () {
    makeSsoDefinition('leave-approval-sso', 'manager', 'sequential');

    $staff = createUserWithRole('staff');
    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Leave request']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'leave-approval-sso', $submitter);

    expect(fn () => $engine->reject($instance, $staff, 'Lý do gì đó'))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// WorkflowServiceProvider default resolver — tích hợp end-to-end
// ---------------------------------------------------------------------------

it('WorkflowServiceProvider default userResolver resolves users via SSO roles relationship', function () {
    // Test này verify rằng WorkflowServiceProvider.registerWorkflowEngine() đúng:
    // userResolver dùng User::whereHas('roles', fn => where slug = role) để tìm approvers
    makeSsoDefinition('sso-flow', 'reviewer', 'sequential');

    // Create 2 reviewers và 1 non-reviewer
    $r1 = createUserWithRole('reviewer');
    $r2 = createUserWithRole('reviewer');
    $nonReviewer = createUserWithRole('viewer'); // khác role

    $submitter = createUserWithoutRole();
    $leaveRequest = FakeLeaveRequest::create(['title' => 'Test']);
    $engine = app(WorkflowEngine::class);

    $instance = $engine->start($leaveRequest, 'sso-flow', $submitter);

    // R1 có role 'reviewer' → có thể approve
    $engine->approve($instance, $r1, 'OK');
    $instance->refresh();
    expect($instance->status)->toBe(WorkflowStatus::Approved);

    // Nếu dùng nonReviewer → lẽ ra phải throw (test riêng ở trên đã cover)
});

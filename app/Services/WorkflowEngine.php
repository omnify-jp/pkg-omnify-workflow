<?php

declare(strict_types=1);

namespace Omnify\Workflow\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Omnify\Workflow\Enums\WorkflowStatus;
use Omnify\Workflow\Enums\WorkflowStepApprovalStatus;
use Omnify\Workflow\Enums\WorkflowStepType;
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
use Omnify\Workflow\Services\Handlers\ParallelStepHandler;
use Omnify\Workflow\Services\Handlers\SerialStepHandler;
use Omnify\Workflow\Services\Handlers\StepHandlerInterface;
use RuntimeException;

/**
 * WorkflowEngine — Xử lý toàn bộ logic của workflow approval system.
 *
 * Đây là service cốt lõi. Tất cả state transitions đều đi qua đây.
 *
 * Thiết kế:
 *   - Stateless: mỗi method nhận đủ parameters để thực hiện
 *   - Transactional: tất cả mutations wrap trong DB::transaction
 *   - Event-driven: fire events sau mỗi transition để host app xử lý notification
 *   - Decoupled: không import User model trực tiếp — dùng $userResolver closure
 *
 * Đăng ký trong WorkflowServiceProvider:
 *   ```php
 *   $this->app->singleton(WorkflowEngine::class, function ($app) {
 *       return new WorkflowEngine(
 *           userResolver: function (string $role, ?string $orgId): Collection {
 *               return \App\Models\User::whereHas('roles', fn($q) =>
 *                   $q->where('slug', $role)
 *                     ->where(fn($q2) => $q2->whereNull('console_organization_id')
 *                         ->orWhere('console_organization_id', $orgId))
 *               )->get();
 *           }
 *       );
 *   });
 *   ```
 */
class WorkflowEngine
{
    /**
     * @param  Closure  $userResolver  Resolve danh sách users theo role + org.
     *                                 Signature: fn(string $role, ?string $orgId): Collection
     *                                 Dùng cho parallel steps (tạo N approval rows).
     */
    public function __construct(
        private readonly Closure $userResolver,
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Bắt đầu workflow mới cho một model.
     *
     * @param  Model  $model  Model chủ (LeaveRequest, PurchaseOrder, v.v.)
     * @param  string  $definitionSlug  Slug của workflow definition
     * @param  Model  $submitter  User submit (phải có 'id' và optionally 'console_organization_id')
     * @param  array  $approverOverrides  Ghi đè approver cụ thể: ['step_1' => userId, 'step_2' => userId]
     * @param  array  $metadata  Context data: ['reason' => '...', 'amount' => 1000]
     *
     * @throws RuntimeException Nếu model đã có active workflow
     * @throws RuntimeException Nếu definition không tồn tại hoặc inactive
     */
    public function start(
        Model $model,
        string $definitionSlug,
        Model $submitter,
        array $approverOverrides = [],
        array $metadata = []
    ): WorkflowInstance {
        // Guard: không cho phép start nếu đã có active workflow
        $existingActive = WorkflowInstance::where('workflowable_type', $model->getMorphClass())
            ->where('workflowable_id', $model->getKey())
            ->where('status', WorkflowStatus::Pending->value)
            ->exists();

        if ($existingActive) {
            throw new RuntimeException('Model đã có workflow đang hoạt động. Hủy workflow hiện tại trước khi tạo mới.');
        }

        $orgId = $submitter->getAttribute('console_organization_id');

        // Resolve definition từ DB hoặc config
        $definition = $this->resolveDefinition($definitionSlug, $orgId);

        if (! $definition->is_active) {
            throw new RuntimeException("Workflow definition '{$definitionSlug}' đang bị tắt (is_active = false).");
        }

        $steps = $this->resolveSteps($definition, $definitionSlug);

        if ($steps->isEmpty()) {
            throw new RuntimeException("Workflow definition '{$definitionSlug}' không có bước nào.");
        }

        return DB::transaction(function () use ($model, $definition, $submitter, $steps, $approverOverrides, $metadata, $orgId) {
            // Tạo workflow instance
            $instance = WorkflowInstance::create([
                'workflow_definition_id' => $definition->exists ? $definition->id : null,
                'workflowable_type' => $model->getMorphClass(),
                'workflowable_id' => (string) $model->getKey(),
                'status' => WorkflowStatus::Pending->value,
                'current_step' => 1,
                'submitted_by' => (string) $submitter->getKey(),
                'submitted_at' => now(),
                'metadata' => $metadata ?: null,
            ]);

            // Ghi history: submitted
            $this->recordHistory($instance, (string) $submitter->getKey(), WorkflowHistory::SUBMITTED, null, WorkflowStatus::Pending->value);

            // Tạo approval rows cho step 1
            $firstStep = $steps->firstWhere('step_order', 1);
            $this->createStepApprovals($instance, $firstStep, $approverOverrides, $orgId);

            // Ghi history: step_started
            $this->recordHistory($instance, null, WorkflowHistory::STEP_STARTED, null, null, step: 1, metadata: ['step_name' => $firstStep->name]);

            event(new WorkflowSubmitted($instance));

            return $instance;
        });
    }

    /**
     * Approver phê duyệt bước hiện tại.
     *
     * Logic:
     *   1. Tìm approval row phù hợp với approver
     *   2. Validate quyền (role check)
     *   3. Đánh dấu approval row → Approved
     *   4. Kiểm tra step có hoàn thành chưa
     *      - Nếu còn pending rows (parallel): chờ
     *      - Nếu step hoàn thành: advance hoặc mark approved
     *
     * @param  Model  $approver  User thực hiện approve (phải có 'id', 'console_organization_id', và roles)
     * @param  ?string  $comment  Nhận xét (optional khi approve)
     *
     * @throws RuntimeException Nếu workflow không còn active
     * @throws RuntimeException Nếu approver không có quyền approve bước này
     */
    public function approve(WorkflowInstance $instance, Model $approver, ?string $comment = null): void
    {
        if ($instance->isCompleted()) {
            throw new RuntimeException('Workflow đã kết thúc, không thể approve.');
        }

        $approverId = (string) $approver->getKey();
        $approverOrg = $approver->getAttribute('console_organization_id');

        DB::transaction(function () use ($instance, $approverId, $approverOrg, $comment) {
            // Tìm approval row phù hợp
            $approval = $this->findApprovableRow($instance, $approverId, $approverOrg);

            // Cập nhật approval row
            $approval->update([
                'approver_id' => $approverId, // Set actual approver nếu row là "any of role"
                'status' => WorkflowStepApprovalStatus::Approved->value,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            // Ghi history: step_approved (dùng cho parallel tracking)
            $this->recordHistory($instance, $approverId, WorkflowHistory::STEP_APPROVED, null, null, step: $instance->current_step, metadata: ['approval_id' => $approval->id]);

            // Kiểm tra step có hoàn thành chưa
            if ($this->isStepComplete($instance)) {
                $this->advanceOrComplete($instance, $approverId, $comment);
            }
        });
    }

    /**
     * Approver từ chối — kết thúc workflow ngay lập tức.
     *
     * @param  string  $comment  Lý do từ chối (bắt buộc — enforced ở đây)
     *
     * @throws RuntimeException Nếu comment rỗng
     * @throws RuntimeException Nếu workflow không còn active
     * @throws RuntimeException Nếu approver không có quyền
     */
    public function reject(WorkflowInstance $instance, Model $approver, string $comment): void
    {
        if (trim($comment) === '') {
            throw new RuntimeException('Lý do từ chối (comment) không được để trống.');
        }

        if ($instance->isCompleted()) {
            throw new RuntimeException('Workflow đã kết thúc, không thể reject.');
        }

        $approverId = (string) $approver->getKey();
        $approverOrg = $approver->getAttribute('console_organization_id');

        DB::transaction(function () use ($instance, $approverId, $approverOrg, $comment) {
            $approval = $this->findApprovableRow($instance, $approverId, $approverOrg);

            // Đánh dấu approval row → Rejected
            $approval->update([
                'approver_id' => $approverId,
                'status' => WorkflowStepApprovalStatus::Rejected->value,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            // Đánh dấu tất cả pending rows còn lại cùng step → Rejected (cleanup)
            WorkflowStepApproval::where('workflow_instance_id', $instance->id)
                ->where('step_order', $instance->current_step)
                ->where('status', WorkflowStepApprovalStatus::Pending->value)
                ->where('id', '!=', $approval->id)
                ->update([
                    'status' => WorkflowStepApprovalStatus::Rejected->value,
                    'decided_at' => now(),
                ]);

            // Đổi instance status → Rejected
            $instance->update([
                'status' => WorkflowStatus::Rejected->value,
                'completed_at' => now(),
            ]);

            $this->recordHistory($instance, $approverId, WorkflowHistory::REJECTED, WorkflowStatus::Pending->value, WorkflowStatus::Rejected->value, step: $instance->current_step, comment: $comment);

            event(new WorkflowRejected($instance->fresh(), $approval->fresh()));
        });
    }

    /**
     * Hủy workflow (submitter hoặc admin).
     *
     * @throws RuntimeException Nếu workflow đã kết thúc
     */
    public function cancel(WorkflowInstance $instance, Model $actor, string $reason): void
    {
        if ($instance->isCompleted()) {
            throw new RuntimeException('Workflow đã kết thúc, không thể hủy.');
        }

        $actorId = (string) $actor->getKey();

        DB::transaction(function () use ($instance, $actorId, $reason) {
            // Đánh dấu tất cả pending approvals → Cancelled (dùng Expired tạm, hoặc giữ Pending)
            // Thực ra không cần update approvals — instance cancelled là đủ

            $instance->update([
                'status' => WorkflowStatus::Cancelled->value,
                'completed_at' => now(),
            ]);

            $this->recordHistory($instance, $actorId, WorkflowHistory::CANCELLED, WorkflowStatus::Pending->value, WorkflowStatus::Cancelled->value, comment: $reason);

            event(new WorkflowCancelled($instance->fresh(), $actorId, $reason));
        });
    }

    /**
     * Escalate tất cả approval rows quá deadline.
     * Gọi từ Artisan command: php artisan workflow:escalate-expired
     *
     * @return int Số approval rows đã được escalate
     */
    public function escalateExpired(): int
    {
        $overdueApprovals = WorkflowStepApproval::where('status', WorkflowStepApprovalStatus::Pending->value)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->with('instance.definition', 'definitionStep')
            ->get();

        $escalated = 0;

        foreach ($overdueApprovals as $approval) {
            $instance = $approval->instance;

            // Skip nếu instance không còn active
            if (! $instance || $instance->isCompleted()) {
                continue;
            }

            DB::transaction(function () use ($approval, $instance, &$escalated) {
                // Lấy escalate_to_role từ definition step
                $escalateToRole = $approval->definitionStep?->escalate_to_role;

                // Đánh dấu row hiện tại → Expired
                $approval->update([
                    'status' => WorkflowStepApprovalStatus::Expired->value,
                    'decided_at' => now(),
                ]);

                if ($escalateToRole === null) {
                    // Không có escalate_to_role → auto-approve
                    $fakeApproval = WorkflowStepApproval::create([
                        'workflow_instance_id' => $instance->id,
                        'workflow_definition_step_id' => $approval->workflow_definition_step_id,
                        'step_order' => $approval->step_order,
                        'approver_id' => null,
                        'approver_role' => null,
                        'status' => WorkflowStepApprovalStatus::Approved->value,
                        'comment' => 'Auto-approved: quá thời hạn SLA',
                        'decided_at' => now(),
                    ]);

                    $this->recordHistory($instance, null, WorkflowHistory::ESCALATED, null, null, step: $approval->step_order, metadata: ['reason' => 'auto-approved on timeout']);

                    // Kiểm tra step complete sau auto-approve
                    if ($this->isStepComplete($instance)) {
                        $this->advanceOrComplete($instance, null, 'Auto-approved: quá thời hạn SLA');
                    }
                } else {
                    // Tạo approval mới với escalate_to_role, deadline 2x
                    $originalDeadlineHours = $approval->definitionStep?->deadline_hours ?? 24;
                    WorkflowStepApproval::create([
                        'workflow_instance_id' => $instance->id,
                        'workflow_definition_step_id' => $approval->workflow_definition_step_id,
                        'step_order' => $approval->step_order,
                        'approver_id' => null,
                        'approver_role' => $escalateToRole,
                        'status' => WorkflowStepApprovalStatus::Pending->value,
                        'deadline_at' => now()->addHours($originalDeadlineHours * 2),
                    ]);

                    $this->recordHistory($instance, null, WorkflowHistory::ESCALATED, null, null, step: $approval->step_order, metadata: ['from_role' => $approval->approver_role, 'to_role' => $escalateToRole]);
                }

                $escalated++;
            });
        }

        return $escalated;
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Resolve WorkflowDefinition từ DB (ưu tiên org-specific) hoặc config.
     *
     * Thứ tự ưu tiên:
     *   1. DB: slug + org_id cụ thể (org override)
     *   2. DB: slug + org_id = null (global default)
     *   3. Config: config('workflow.definitions.{slug}')
     *   4. Throw exception nếu không tìm thấy
     */
    private function resolveDefinition(string $slug, ?string $orgId): WorkflowDefinition
    {
        // Tìm org-specific trước
        if ($orgId) {
            $definition = WorkflowDefinition::where('slug', $slug)
                ->where('console_organization_id', $orgId)
                ->first();

            if ($definition) {
                return $definition;
            }
        }

        // Tìm global (console_organization_id = null)
        $definition = WorkflowDefinition::where('slug', $slug)
            ->whereNull('console_organization_id')
            ->first();

        if ($definition) {
            return $definition;
        }

        // Fallback về config
        $config = config("workflow.definitions.{$slug}");

        if ($config) {
            return WorkflowDefinition::fromConfig($slug, $config);
        }

        throw new RuntimeException("Workflow definition '{$slug}' không tìm thấy trong DB hoặc config.");
    }

    /**
     * Resolve danh sách steps của definition.
     * Nếu definition đến từ config (exists = false), parse steps từ config.
     */
    private function resolveSteps(WorkflowDefinition $definition, string $slug): Collection
    {
        if ($definition->exists) {
            return $definition->steps()->get();
        }

        // Definition đến từ config → build steps từ config array
        $stepsConfig = config("workflow.definitions.{$slug}.steps", []);
        $steps = collect();

        foreach ($stepsConfig as $index => $stepConfig) {
            $step = WorkflowDefinitionStep::fromConfig(
                definitionId: $slug, // dùng slug làm fake ID
                stepOrder: $index + 1,
                config: $stepConfig
            );
            $steps->push($step);
        }

        return $steps;
    }

    /**
     * Resolve the correct step handler based on step type.
     *
     * Parallel steps use ParallelStepHandler (N approval rows).
     * Sequential and any_of steps use SerialStepHandler (1 approval row).
     */
    private function resolveHandler(WorkflowDefinitionStep $step): StepHandlerInterface
    {
        return match ($step->type) {
            WorkflowStepType::Parallel => app(ParallelStepHandler::class),
            default => app(SerialStepHandler::class),
        };
    }

    /**
     * Tạo WorkflowStepApproval rows cho 1 step.
     *
     * Delegates to the appropriate StepHandlerInterface implementation
     * based on step type (serial vs parallel).
     */
    private function createStepApprovals(
        WorkflowInstance $instance,
        WorkflowDefinitionStep $step,
        array $approverOverrides,
        ?string $orgId
    ): void {
        $handler = $this->resolveHandler($step);
        $handler->createApprovals($instance, $step, $approverOverrides, $orgId, $this->userResolver);
    }

    /**
     * Kiểm tra bước hiện tại đã hoàn thành chưa.
     *
     * Delegates to the appropriate handler for the current step.
     * Falls back to SerialStepHandler if the step model cannot be resolved.
     */
    private function isStepComplete(WorkflowInstance $instance): bool
    {
        $currentStepModel = $instance->definition?->steps()
            ->where('step_order', $instance->current_step)
            ->first();

        if (! $currentStepModel) {
            // Fallback: use serial handler (same completion logic regardless of type)
            return app(SerialStepHandler::class)->isComplete($instance);
        }

        return $this->resolveHandler($currentStepModel)->isComplete($instance);
    }

    /**
     * Advance sang bước tiếp hoặc mark instance là Approved.
     * Gọi sau khi isStepComplete() = true.
     */
    private function advanceOrComplete(WorkflowInstance $instance, ?string $actorId, ?string $comment): void
    {
        $completedStep = $instance->current_step;

        event(new WorkflowStepCompleted($instance->fresh(), $completedStep));

        $this->recordHistory($instance, null, WorkflowHistory::STEP_COMPLETED, null, null, step: $completedStep);

        // Tìm definition để biết total steps
        $totalSteps = $this->getTotalSteps($instance);
        $nextStep = $completedStep + 1;

        if ($nextStep > $totalSteps) {
            // Đây là bước cuối — workflow approved
            $instance->update([
                'status' => WorkflowStatus::Approved->value,
                'completed_at' => now(),
            ]);

            $this->recordHistory($instance, $actorId, WorkflowHistory::APPROVED, WorkflowStatus::Pending->value, WorkflowStatus::Approved->value, comment: $comment);

            event(new WorkflowApproved($instance->fresh()));
        } else {
            // Còn bước tiếp — advance
            $instance->update(['current_step' => $nextStep]);

            $definition = $instance->definition;
            $orgId = null; // Lấy org từ submitter nếu cần cho parallel

            if ($definition) {
                $nextStepModel = $definition->steps()->where('step_order', $nextStep)->first();
            } else {
                // Definition từ config — resolve lại
                $slug = config('workflow.definitions');
                $nextStepModel = null;

                // Tìm từ approvals của instance để biết definition step config
                $prevApproval = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
                    ->where('step_order', $nextStep)
                    ->first();

                if (! $nextStepModel && $prevApproval?->definitionStep) {
                    $nextStepModel = $prevApproval->definitionStep;
                }
            }

            if ($nextStepModel) {
                $this->createStepApprovals($instance, $nextStepModel, [], $orgId);
                $this->recordHistory($instance, null, WorkflowHistory::STEP_STARTED, null, null, step: $nextStep, metadata: ['step_name' => $nextStepModel->name]);
            }
        }
    }

    /**
     * Lấy tổng số steps của workflow instance.
     * Ưu tiên count từ DB definition, fallback về đếm approval rows.
     */
    private function getTotalSteps(WorkflowInstance $instance): int
    {
        if ($instance->workflow_definition_id && $instance->definition) {
            return $instance->definition->steps()->count();
        }

        // Fallback: lấy max step_order từ approval rows
        return WorkflowStepApproval::where('workflow_instance_id', $instance->id)
            ->max('step_order') ?? 1;
    }

    /**
     * Tìm approval row mà approver có thể thực hiện action.
     *
     * Cho sequential/any_of: tìm row với approver_id = null VÀ approver có role phù hợp
     * Cho parallel: tìm row với approver_id = approver's ID cụ thể
     *
     * @throws RuntimeException Nếu không tìm thấy row phù hợp
     */
    private function findApprovableRow(WorkflowInstance $instance, string $approverId, ?string $orgId): WorkflowStepApproval
    {
        // Tìm row được assign cụ thể cho user này
        $assigned = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
            ->where('step_order', $instance->current_step)
            ->where('approver_id', $approverId)
            ->where('status', WorkflowStepApprovalStatus::Pending->value)
            ->first();

        if ($assigned) {
            return $assigned;
        }

        // Tìm row "any of role" và validate role của approver
        $anyOfRow = WorkflowStepApproval::where('workflow_instance_id', $instance->id)
            ->where('step_order', $instance->current_step)
            ->whereNull('approver_id')
            ->where('status', WorkflowStepApprovalStatus::Pending->value)
            ->first();

        if ($anyOfRow) {
            // Validate approver có role phù hợp không (nếu có approver_role yêu cầu)
            if ($anyOfRow->approver_role !== null) {
                $hasRole = $this->userHasRole($approverId, $anyOfRow->approver_role, $orgId);

                if (! $hasRole) {
                    throw new RuntimeException("Bạn không có quyền approve bước này. Yêu cầu role: '{$anyOfRow->approver_role}'.");
                }
            }

            return $anyOfRow;
        }

        throw new RuntimeException('Không tìm thấy approval cần xử lý. Bạn không phải approver của bước này hoặc bước đã được xử lý.');
    }

    /**
     * Kiểm tra user có role trong org không.
     * Dùng userResolver để resolve users và kiểm tra.
     */
    private function userHasRole(string $userId, string $role, ?string $orgId): bool
    {
        $usersWithRole = ($this->userResolver)($role, $orgId);

        return $usersWithRole->contains(function ($user) use ($userId) {
            return (string) $user->getKey() === $userId;
        });
    }

    /**
     * Ghi 1 dòng vào workflow_history.
     */
    private function recordHistory(
        WorkflowInstance $instance,
        ?string $actorId,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $step = null,
        ?string $comment = null,
        array $metadata = []
    ): void {
        WorkflowHistory::create([
            'workflow_instance_id' => $instance->id,
            'actor_id' => $actorId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'step_order' => $step,
            'comment' => $comment,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}

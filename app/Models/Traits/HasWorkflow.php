<?php

declare(strict_types=1);

namespace Omnify\Workflow\Models\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Omnify\Workflow\Enums\WorkflowStatus;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Services\WorkflowEngine;

/**
 * HasWorkflow — Trait để thêm workflow support vào bất kỳ Eloquent model nào.
 *
 * Cách sử dụng:
 *   ```php
 *   class LeaveRequest extends Model {
 *       use HasWorkflow;
 *   }
 *
 *   // Start workflow
 *   $instance = $leaveRequest->startWorkflow(
 *       definitionSlug: 'leave-approval',
 *       submitter: auth()->user(),
 *       approverOverrides: ['step_1' => $managerId],
 *       metadata: ['reason' => 'Việc gia đình', 'days' => 3]
 *   );
 *
 *   // Check status
 *   $leaveRequest->hasActiveWorkflow(); // true
 *   $leaveRequest->workflowStatus();   // WorkflowStatus::Pending
 *
 *   // Approve (từ controller của approver)
 *   $activeWorkflow = $leaveRequest->activeWorkflow();
 *   app(WorkflowEngine::class)->approve($activeWorkflow, auth()->user(), 'OK, đồng ý');
 *   ```
 */
trait HasWorkflow
{
    /**
     * Tất cả workflow instances của model này (morph relationship).
     * Một model có thể có nhiều instances theo thời gian (submit lại sau khi bị reject).
     */
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'workflowable');
    }

    /**
     * Workflow instance đang hoạt động hiện tại (status = pending).
     * Trả về null nếu không có workflow đang chạy.
     */
    public function activeWorkflow(): ?WorkflowInstance
    {
        return $this->workflowInstances()
            ->where('status', WorkflowStatus::Pending->value)
            ->latest()
            ->first();
    }

    /**
     * Workflow instance mới nhất (bất kể status).
     * Dùng để hiển thị trạng thái cuối cùng của workflow.
     */
    public function latestWorkflow(): ?WorkflowInstance
    {
        return $this->workflowInstances()->latest()->first();
    }

    /**
     * Kiểm tra model có đang có workflow active không.
     */
    public function hasActiveWorkflow(): bool
    {
        return $this->workflowInstances()
            ->where('status', WorkflowStatus::Pending->value)
            ->exists();
    }

    /**
     * Lấy trạng thái của workflow mới nhất.
     * Trả về null nếu chưa có workflow nào.
     */
    public function workflowStatus(): ?WorkflowStatus
    {
        return $this->latestWorkflow()?->status;
    }

    /**
     * Bắt đầu một workflow mới cho model này.
     *
     * @param  string  $definitionSlug  Slug của workflow definition (VD: 'leave-approval')
     * @param  Model  $submitter  User submit workflow (phải có console_organization_id nếu dùng RBAC)
     * @param  array  $approverOverrides  Ghi đè approver cụ thể cho từng step.
     *                                    Key: 'step_{order}', Value: user UUID
     *                                    VD: ['step_1' => 'uuid-of-manager']
     * @param  array  $metadata  Context data từ application (không xử lý bởi engine)
     *                           VD: ['reason' => 'Việc gia đình', 'days' => 3]
     *
     * @throws \RuntimeException Nếu đã có active workflow
     * @throws \RuntimeException Nếu definition không tìm thấy hoặc inactive
     */
    public function startWorkflow(
        string $definitionSlug,
        Model $submitter,
        array $approverOverrides = [],
        array $metadata = []
    ): WorkflowInstance {
        return app(WorkflowEngine::class)->start(
            model: $this,
            definitionSlug: $definitionSlug,
            submitter: $submitter,
            approverOverrides: $approverOverrides,
            metadata: $metadata,
        );
    }
}

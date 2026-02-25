<?php

declare(strict_types=1);

namespace Omnify\Workflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Omnify\Workflow\Database\Factories\WorkflowInstanceFactory;
use Omnify\Workflow\Enums\WorkflowStatus;

class WorkflowInstance extends Model
{
    use HasFactory;

    protected static function newFactory(): WorkflowInstanceFactory
    {
        return WorkflowInstanceFactory::new();
    }

    use HasUuids;

    protected $table = 'workflow_instances';

    protected $fillable = [
        'workflow_definition_id',
        'workflowable_type',
        'workflowable_id',
        'status',
        'current_step',
        'submitted_by',
        'submitted_at',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'current_step' => 'integer',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** WorkflowDefinition (template) của instance này. */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    /** Polymorphic: model đang được workflow xử lý (LeaveRequest, PurchaseOrder, v.v.) */
    public function workflowable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Tất cả approval rows của instance này. */
    public function stepApprovals(): HasMany
    {
        return $this->hasMany(WorkflowStepApproval::class);
    }

    /** Approval rows của bước hiện tại (current_step). */
    public function currentStepApprovals(): HasMany
    {
        return $this->hasMany(WorkflowStepApproval::class)
            ->where('step_order', $this->current_step);
    }

    /** Approval rows đang pending của bước hiện tại. */
    public function pendingStepApprovals(): HasMany
    {
        return $this->hasMany(WorkflowStepApproval::class)
            ->where('step_order', $this->current_step)
            ->where('status', 'pending');
    }

    /** Lịch sử của instance này, theo thứ tự thời gian tăng dần. */
    public function history(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class)->orderBy('created_at');
    }

    /** Kiểm tra workflow còn đang hoạt động không. */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** Kiểm tra workflow đã kết thúc chưa. */
    public function isCompleted(): bool
    {
        return $this->status->isTerminal();
    }

    /** Kiểm tra user có phải submitter không. */
    public function isSubmittedBy(string $userId): bool
    {
        return $this->submitted_by === $userId;
    }
}

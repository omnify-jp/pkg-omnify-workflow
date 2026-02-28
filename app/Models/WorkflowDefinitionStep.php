<?php

declare(strict_types=1);

namespace Omnify\Workflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnify\Workflow\Database\Factories\WorkflowDefinitionStepFactory;
use Omnify\Workflow\Enums\WorkflowStepType;

class WorkflowDefinitionStep extends Model
{
    use HasFactory;
    use HasUuids;

    protected static function newFactory(): WorkflowDefinitionStepFactory
    {
        return WorkflowDefinitionStepFactory::new();
    }

    protected $table = 'workflow_definition_steps';

    protected $fillable = [
        'workflow_definition_id',
        'step_order',
        'name',
        'description',
        'approver_role',
        'approver_user_ids',
        'type',
        'deadline_hours',
        'escalate_to_role',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'type' => WorkflowStepType::class,
            'deadline_hours' => 'integer',
            'approver_user_ids' => 'array',
        ];
    }

    /** WorkflowDefinition chứa bước này. */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    /**
     * Kiểm tra xem bước này có yêu cầu resolve danh sách users không.
     * Chỉ parallel mới cần (để tạo N rows, 1 per user).
     */
    public function requiresUserResolution(): bool
    {
        return $this->type === WorkflowStepType::Parallel;
    }

    /**
     * Tính deadline_at từ thời điểm bước được kích hoạt.
     * Trả về null nếu không có deadline_hours.
     */
    public function calculateDeadlineAt(?\DateTimeInterface $from = null): ?\Carbon\Carbon
    {
        if (! $this->deadline_hours) {
            return null;
        }

        return \Carbon\Carbon::parse($from ?? now())->addHours($this->deadline_hours);
    }

    /**
     * Tạo WorkflowDefinitionStep "ảo" từ config array (không lưu DB).
     * Dùng khi definition đến từ config file.
     */
    public static function fromConfig(string $definitionId, int $stepOrder, array $config): self
    {
        $step = new self([
            'workflow_definition_id' => $definitionId,
            'step_order' => $stepOrder,
            'name' => $config['name'] ?? "Step {$stepOrder}",
            'description' => $config['description'] ?? null,
            'approver_role' => $config['approver_role'] ?? null,
            'approver_user_ids' => $config['approver_user_ids'] ?? null,
            'type' => $config['type'] ?? WorkflowStepType::Sequential->value,
            'deadline_hours' => $config['deadline_hours'] ?? null,
            'escalate_to_role' => $config['escalate_to_role'] ?? null,
        ]);

        $step->exists = false;

        return $step;
    }
}

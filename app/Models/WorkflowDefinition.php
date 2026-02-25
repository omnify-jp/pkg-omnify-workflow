<?php

declare(strict_types=1);

namespace Omnify\Workflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnify\Workflow\Database\Factories\WorkflowDefinitionFactory;

class WorkflowDefinition extends Model
{
    use HasFactory;
    use HasUuids;

    protected static function newFactory(): WorkflowDefinitionFactory
    {
        return WorkflowDefinitionFactory::new();
    }

    protected $table = 'workflow_definitions';

    protected $fillable = [
        'slug',
        'console_organization_id',
        'name',
        'description',
        'is_active',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }

    /** Các bước của quy trình này, theo thứ tự step_order. */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowDefinitionStep::class)->orderBy('step_order');
    }

    /** Các lần chạy thực tế (instances) của quy trình này. */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    /**
     * Tổng số steps của quy trình.
     * Dùng để xác định "bước cuối cùng" trong WorkflowEngine.
     */
    public function totalSteps(): int
    {
        return $this->steps()->count();
    }

    /**
     * Tạo WorkflowDefinition "ảo" từ config array (không lưu DB).
     * Dùng khi definition chỉ có trong config file, không có trong DB.
     */
    public static function fromConfig(string $slug, array $config): self
    {
        $definition = new self([
            'slug' => $slug,
            'console_organization_id' => null,
            'name' => $config['name'] ?? $slug,
            'description' => $config['description'] ?? null,
            'is_active' => true,
            'config' => $config['config'] ?? null,
        ]);

        // Không persist (exists = false) — chỉ dùng trong memory
        $definition->exists = false;

        return $definition;
    }
}

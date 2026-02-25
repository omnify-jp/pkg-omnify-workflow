<?php

declare(strict_types=1);

namespace Omnify\Workflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowHistory extends Model
{
    use HasUuids;

    protected $table = 'workflow_history';

    /**
     * Bảng này chỉ có created_at, không có updated_at.
     * Rows là immutable — không bao giờ update.
     */
    public $timestamps = false;

    protected $fillable = [
        'workflow_instance_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'step_order',
        'comment',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** WorkflowInstance của history row này. */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    /**
     * Danh sách action names chuẩn.
     * Dùng như constants để tránh typo khi ghi history.
     */
    public const SUBMITTED = 'submitted';

    public const STEP_STARTED = 'step_started';

    public const STEP_APPROVED = 'step_approved';

    public const STEP_COMPLETED = 'step_completed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const ESCALATED = 'escalated';

    public const EXPIRED = 'expired';
}

<?php

declare(strict_types=1);

namespace Omnify\Workflow\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Omnify\Workflow\Models\Traits\HasWorkflow;

/**
 * FakeLeaveRequest — Model giả lập để test HasWorkflow trait.
 *
 * Đại diện cho bất kỳ model nào có thể gắn workflow.
 */
class FakeLeaveRequest extends Model
{
    use HasUuids;
    use HasWorkflow;

    protected $table = 'fake_leave_requests';

    protected $fillable = ['title'];
}

<?php

declare(strict_types=1);

namespace Omnify\Workflow\Tests\Fixtures\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * FakeUser — Minimal user model cho tests.
 *
 * Chỉ có các fields cần thiết để WorkflowEngine hoạt động:
 *   - id (UUID)
 *   - role (single role, để đơn giản hóa tests)
 *   - console_organization_id (nullable)
 *
 * Implements Authenticatable để dùng với actingAs() trong API tests.
 */
class FakeUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasUuids;

    protected $table = 'fake_users';

    protected $fillable = [
        'name',
        'role',
        'console_organization_id',
    ];

    /** Helper: kiểm tra user có role không (dùng trong cancel authorization). */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }
}

<?php

/**
 * Workflow Package Tests Configuration
 */

use Omnify\Core\Models\Role;
use Omnify\Core\Models\User;
use Omnify\Workflow\Tests\Fixtures\Models\FakeUser;
use Omnify\Workflow\Tests\Integration\SsoIntegrationTestCase;
use Omnify\Workflow\Tests\TestCase;

// Feature/Unit: dùng FakeUser (isolated, không phụ thuộc SSO)
uses(TestCase::class)->in('Feature', 'Unit');

// Integration: dùng real SSO User + WorkflowServiceProvider's default userResolver
uses(SsoIntegrationTestCase::class)->in('Integration');

// ---------------------------------------------------------------------------
// Feature/Unit helpers — dùng FakeUser
// ---------------------------------------------------------------------------

/**
 * Tạo FakeUser với role cụ thể (cho Feature/Unit tests).
 */
function createUser(string $role = 'manager', array $attributes = []): FakeUser
{
    return FakeUser::create(array_merge(['role' => $role], $attributes));
}

// ---------------------------------------------------------------------------
// Integration helpers — dùng real SSO User
// ---------------------------------------------------------------------------

/**
 * Tạo SSO User với global role (console_organization_id = null).
 * Dùng trong Integration tests để test userResolver với real RBAC.
 */
function createUserWithRole(string $roleSlug, array $userAttributes = []): User
{
    $role = Role::firstOrCreate(
        ['slug' => $roleSlug, 'console_organization_id' => null],
        ['name' => ucfirst($roleSlug), 'level' => 1, 'description' => null],
    );

    $user = User::factory()->create($userAttributes);
    $user->assignRole($role);

    return $user;
}

/**
 * Tạo SSO User không có role nào (submitter hoặc unauthorized approver).
 */
function createUserWithoutRole(array $userAttributes = []): User
{
    return User::factory()->create($userAttributes);
}

<?php

declare(strict_types=1);

namespace Omnify\Workflow\Tests\Integration;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Omnify\SsoClient\Models\Role;
use Omnify\SsoClient\Models\User;
use Omnify\SsoClient\SsoClientServiceProvider;
use Omnify\Workflow\Tests\Fixtures\Models\FakeLeaveRequest;
use Omnify\Workflow\WorkflowServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base TestCase cho workflow-SSO integration tests.
 *
 * Mục đích: Verify rằng WorkflowEngine hoạt động đúng trong môi trường thực tế,
 * nơi host app dùng `omnifyjp/omnify-client-laravel-sso` cho authentication &
 * authorization (KHÔNG có auth riêng).
 *
 * Khác với TestCase cơ bản:
 *   - KHÔNG override WorkflowEngine singleton — dùng default resolver từ WorkflowServiceProvider
 *   - Load SsoClientServiceProvider để register User model + roles relationship
 *   - Dùng real SSO User với roles() BelongsToMany qua pivot `role_user`
 *   - Config `omnify-auth.user_model` = User::class (SSO User)
 *
 * Kết quả: Tests verify rằng userResolver trong WorkflowServiceProvider đúng với
 * real RBAC — users được resolve đúng theo role, parallel approval tạo N rows, v.v.
 */
abstract class SsoIntegrationTestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // SsoClient's BaseModel::boot() gọi Relation::enforceMorphMap() với danh sách SSO models.
        // FakeLeaveRequest không có trong SSO's modelMap → ClassMorphViolationException.
        // Giải pháp: thêm FakeLeaveRequest vào morph map SAU khi setUp() chạy xong.
        // morphMap($map, merge=true) sẽ giữ lại FakeLeaveRequest kể cả khi BaseModel::boot() chạy sau.
        Relation::morphMap([
            'FakeLeaveRequest' => FakeLeaveRequest::class,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            SsoClientServiceProvider::class,
            WorkflowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'workflow_testing',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);

        // SSO config tối thiểu (không kết nối console thật)
        $app['config']->set('omnify-auth.console.url', 'https://test.console.omnify.jp');
        $app['config']->set('omnify-auth.service.slug', 'test-service');
        $app['config']->set('omnify-auth.service.secret', 'test-secret');

        // Quan trọng: User model của SSO (thay vì FakeUser)
        $app['config']->set('omnify-auth.user_model', User::class);

        // Tắt Inertia-based SSO routes để tránh phụ thuộc không cần thiết trong tests
        $app['config']->set('omnify-auth.routes.auth_enabled', false);
        $app['config']->set('omnify-auth.routes.access_enabled', false);

        // Auth guard dùng real SSO User
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);

        // Workflow routes: dùng ['web'] (không cần sso.auth middleware trong tests)
        $app['config']->set('workflow.routes.middleware', ['web']);
        $app['config']->set('workflow.routes.prefix', 'api/workflow');
        $app['config']->set('workflow.routes.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        // SSO migrations: users, roles, permissions, role_user, role_permissions, v.v.
        // Lấy đường dẫn từ SsoClientServiceProvider để hoạt động đúng ở cả local & vendor
        $ssoProviderDir = dirname((new \ReflectionClass(SsoClientServiceProvider::class))->getFileName());
        $this->loadMigrationsFrom($ssoProviderDir.'/../database/migrations/omnify');

        // Workflow migrations: workflow_definitions, workflow_instances, v.v.
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Fixture: fake_leave_requests (model workflowable dùng trong tests)
        // fake_users table cũng được tạo nhưng không dùng trong integration tests
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures/database/migrations');
    }

    /**
     * Tạo SSO User với global role (console_organization_id = null trên Role).
     *
     * "Global role" = role không bị scoped vào org cụ thể.
     * userResolver trong WorkflowServiceProvider filter `roles.console_organization_id IS NULL`
     * nên global roles luôn được tìm thấy bất kể org của submitter.
     */
    protected function createUserWithRole(string $roleSlug, array $userAttributes = []): User
    {
        // firstOrCreate: tránh duplicate trong cùng 1 test (unique constraint trên slug+org)
        $role = Role::firstOrCreate(
            ['slug' => $roleSlug, 'console_organization_id' => null],
            ['name' => ucfirst($roleSlug), 'level' => 1, 'description' => null],
        );

        $user = User::factory()->create($userAttributes);
        $user->assignRole($role);

        return $user;
    }

    /** Tạo SSO User không có role nào — dùng làm submitter hoặc unauthorized approver. */
    protected function createUserWithoutRole(array $userAttributes = []): User
    {
        return User::factory()->create($userAttributes);
    }
}

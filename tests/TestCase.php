<?php

declare(strict_types=1);

namespace Omnify\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Omnify\Workflow\Services\WorkflowEngine;
use Omnify\Workflow\Tests\Fixtures\Models\FakeUser;
use Omnify\Workflow\WorkflowServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base TestCase cho pkg-omnify-workflow tests.
 *
 * Cấu hình:
 *   - SQLite in-memory
 *   - WorkflowServiceProvider loaded
 *   - WorkflowEngine với userResolver đơn giản (FakeUser → role)
 *   - RefreshDatabase giữa các tests
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            WorkflowServiceProvider::class,
            \Inertia\ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // WorkflowEngine với userResolver dùng FakeUser.role
        $app->singleton(WorkflowEngine::class, function () {
            return new WorkflowEngine(
                userResolver: function (string $role, ?string $orgId) {
                    return FakeUser::where('role', $role)->get();
                }
            );
        });

        // Auth: web guard với FakeUser để actingAs() hoạt động
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => FakeUser::class,
        ]);

        // Route middleware: dùng web (không cần sso.auth)
        $app['config']->set('workflow.routes.middleware', ['web']);
        $app['config']->set('workflow.routes.prefix', 'api/workflow');
        $app['config']->set('workflow.routes.enabled', true);

        // Admin routes: dùng web + auth
        $app['config']->set('workflow.admin.enabled', true);
        $app['config']->set('workflow.admin.prefix', 'admin/workflow');
        $app['config']->set('workflow.admin.middleware', ['web']);

        // View paths: Inertia cần app.blade.php
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);

        // Disable Inertia page file existence check (pages live in package, not host app)
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }

    /** Tạo FakeUser với role cụ thể. */
    protected function createUser(string $role = 'manager', array $attributes = []): FakeUser
    {
        return FakeUser::create(array_merge(['role' => $role], $attributes));
    }
}

<?php

declare(strict_types=1);

namespace Omnify\Workflow;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Omnify\Workflow\Console\Commands\EscalateExpiredWorkflowsCommand;
use Omnify\Workflow\Services\Handlers\ParallelStepHandler;
use Omnify\Workflow\Services\Handlers\SerialStepHandler;
use Omnify\Workflow\Services\WorkflowEngine;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workflow.php', 'workflow');

        $this->registerHandlers();
        $this->registerWorkflowEngine();
    }

    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerRoutes();
        $this->registerPublishing();
        $this->commands([
            EscalateExpiredWorkflowsCommand::class,
        ]);
    }

    // =========================================================================

    /**
     * Register step handler classes as simple (stateless) bindings.
     *
     * Handlers are stateless so singleton is not needed — a fresh instance
     * per resolve is fine and avoids hidden state between requests.
     */
    private function registerHandlers(): void
    {
        $this->app->bind(SerialStepHandler::class);
        $this->app->bind(ParallelStepHandler::class);
    }

    /**
     * Đăng ký WorkflowEngine singleton với userResolver closure.
     *
     * userResolver: Closure(string $role, ?string $orgId): Collection
     *   Nhận role slug và org ID, trả về danh sách users có role đó trong org.
     *   Dùng cho parallel steps (tạo 1 approval row per user).
     *
     * Coupling với SsoClient:
     *   WorkflowEngine không import User model trực tiếp.
     *   Thay vào đó, ServiceProvider inject closure này để resolve users.
     *   Nếu host app không dùng SsoClient, có thể override bằng cách rebind:
     *
     *   app()->singleton(WorkflowEngine::class, fn() => new WorkflowEngine(
     *       userResolver: fn($role, $orgId) => MyUser::whereRole($role)->get()
     *   ));
     */
    private function registerWorkflowEngine(): void
    {
        $this->app->singleton(WorkflowEngine::class, function () {
            return new WorkflowEngine(
                userResolver: function (string $role, ?string $orgId) {
                    // Resolve users via SsoClient's User model (if available)
                    // This uses the application's User model (configured in omnify-auth.user_model)
                    $userModelClass = config('omnify-auth.user_model', \App\Models\User::class);

                    if (! class_exists($userModelClass)) {
                        return collect();
                    }

                    return $userModelClass::whereHas('roles', function ($q) use ($role, $orgId) {
                        $q->where('roles.slug', $role)
                            ->where(function ($q2) use ($orgId) {
                                $q2->whereNull('roles.console_organization_id');

                                if ($orgId) {
                                    $q2->orWhere('roles.console_organization_id', $orgId);
                                }
                            });
                    })->get();
                }
            );
        });
    }

    /**
     * Load migrations từ thư mục package.
     * Host app không cần publish để migrations chạy.
     */
    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/omnify');
    }

    /**
     * Mount workflow routes nếu được bật trong config.
     */
    private function registerRoutes(): void
    {
        if (! config('workflow.routes.enabled', true)) {
            return;
        }

        Route::middleware(config('workflow.routes.middleware', ['web', 'sso.auth']))
            ->prefix(config('workflow.routes.prefix', 'api/workflow'))
            ->group(__DIR__.'/../routes/workflow.php');
    }

    /**
     * Publish config và migrations cho host app.
     */
    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/workflow.php' => config_path('workflow.php'),
        ], 'workflow-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations/workflow'),
        ], 'workflow-migrations');
    }
}

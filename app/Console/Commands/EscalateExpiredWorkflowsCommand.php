<?php

declare(strict_types=1);

namespace Omnify\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Omnify\Workflow\Services\WorkflowEngine;

/**
 * EscalateExpiredWorkflowsCommand — Xử lý các workflow bị quá hạn SLA.
 *
 * Chức năng:
 *   Tìm tất cả WorkflowStepApproval có status = pending VÀ deadline_at < NOW().
 *   Với mỗi approval quá hạn:
 *     - Nếu step có escalate_to_role: tạo approval mới với role đó (deadline 2x)
 *     - Nếu không có escalate_to_role: auto-approve (không block workflow)
 *
 * Cách chạy thủ công:
 *   php artisan workflow:escalate-expired
 *   php artisan workflow:escalate-expired --dry-run   (xem trước, không thực hiện)
 *
 * Cách schedule (trong routes/console.php hoặc Kernel):
 *   Schedule::command('workflow:escalate-expired')->hourly();
 *   // Hoặc nếu cần kiểm tra mỗi 15 phút:
 *   Schedule::command('workflow:escalate-expired')->everyFifteenMinutes();
 *
 * Lưu ý:
 *   - Command idempotent: chạy nhiều lần không gây lỗi (approvals đã Expired sẽ bị skip)
 *   - Recommended: chạy ít nhất hourly để SLA không bị chậm quá 1h
 */
class EscalateExpiredWorkflowsCommand extends Command
{
    protected $signature = 'workflow:escalate-expired
        {--dry-run : Chỉ hiển thị, không thực hiện escalation}';

    protected $description = 'Escalate các workflow step approvals quá hạn SLA';

    public function __construct(private readonly WorkflowEngine $engine)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            return $this->runDryRun();
        }

        $this->info('Đang kiểm tra các workflow quá hạn SLA...');

        $count = $this->engine->escalateExpired();

        if ($count === 0) {
            $this->info('Không có workflow nào quá hạn.');
        } else {
            $this->info("Đã xử lý <fg=yellow>{$count}</> approval(s) quá hạn.");
        }

        return self::SUCCESS;
    }

    private function runDryRun(): int
    {
        $this->warn('=== DRY RUN — không thực hiện escalation ===');

        $overdueApprovals = \Omnify\Workflow\Models\WorkflowStepApproval::where('status', 'pending')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->with(['instance', 'definitionStep'])
            ->get();

        if ($overdueApprovals->isEmpty()) {
            $this->info('Không có approval nào quá hạn.');

            return self::SUCCESS;
        }

        $this->line("Tìm thấy <fg=yellow>{$overdueApprovals->count()}</> approval(s) quá hạn:");
        $this->newLine();

        foreach ($overdueApprovals as $approval) {
            $escalateTo = $approval->definitionStep?->escalate_to_role ?? '(auto-approve)';
            $overdue = $approval->deadline_at?->diffForHumans() ?? 'unknown';

            $this->line(sprintf(
                '  [%s] Step %d — role: %s → escalate to: %s (quá hạn: %s)',
                $approval->workflow_instance_id,
                $approval->step_order,
                $approval->approver_role ?? '(any)',
                $escalateTo,
                $overdue
            ));
        }

        $this->newLine();
        $this->warn('=== DRY RUN complete — không có gì được thực hiện ===');

        return self::SUCCESS;
    }
}

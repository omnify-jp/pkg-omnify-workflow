<?php

declare(strict_types=1);

namespace Omnify\Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Omnify\Workflow\Enums\WorkflowStepApprovalStatus;
use Omnify\Workflow\Models\WorkflowInstance;
use Omnify\Workflow\Services\WorkflowEngine;
use Symfony\Component\HttpFoundation\Response;

/**
 * WorkflowInstanceController — REST API cho workflow instances.
 *
 * Base URL: /api/workflow (configurable via WORKFLOW_ROUTE_PREFIX)
 *
 * Routes:
 *   GET  /api/workflow/pending          → Danh sách việc cần duyệt của tôi
 *   GET  /api/workflow/instances/{id}   → Chi tiết 1 instance + history
 *   POST /api/workflow/instances/{id}/approve → Phê duyệt
 *   POST /api/workflow/instances/{id}/reject  → Từ chối
 *   POST /api/workflow/instances/{id}/cancel  → Hủy
 */
class WorkflowInstanceController extends Controller
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * GET /api/workflow/pending
     *
     * Danh sách workflow instances đang chờ approver hiện tại xử lý.
     * Approver chỉ thấy instances mà họ có pending approval rows.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $instances = WorkflowInstance::where('status', 'pending')
            ->whereHas('stepApprovals', function ($q) use ($user) {
                $q->where('status', WorkflowStepApprovalStatus::Pending->value)
                    ->where(function ($q2) use ($user) {
                        // Row được assign cụ thể cho user này
                        $q2->where('approver_id', $user->getKey())
                            // Hoặc row "any of role" (approver_id = null)
                            ->orWhereNull('approver_id');
                    });
            })
            ->where(function ($q) {
                // Chỉ lấy instances có current_step = step_order của approval row
                $q->whereRaw('current_step = (SELECT MIN(step_order) FROM workflow_step_approvals WHERE workflow_instance_id = workflow_instances.id AND status = ?)', ['pending']);
            })
            ->with(['definition', 'stepApprovals' => function ($q) {
                $q->where('status', WorkflowStepApprovalStatus::Pending->value);
            }])
            ->latest('submitted_at')
            ->paginate(20);

        return response()->json($instances);
    }

    /**
     * GET /api/workflow/instances/{id}
     *
     * Chi tiết 1 workflow instance: thông tin, approvals, history.
     * Có thể xem bởi: submitter, approvers hiện tại, admin.
     */
    public function show(string $id): JsonResponse
    {
        $instance = WorkflowInstance::with([
            'definition.steps',
            'stepApprovals' => fn ($q) => $q->orderBy('step_order')->orderBy('created_at'),
            'history',
        ])->findOrFail($id);

        return response()->json([
            'instance' => $instance,
            'history' => $instance->history,
            'approvals' => $instance->stepApprovals,
        ]);
    }

    /**
     * POST /api/workflow/instances/{id}/approve
     *
     * Body: { "comment": "Đồng ý" }
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $instance = WorkflowInstance::findOrFail($id);
        $comment = $request->string('comment')->value() ?: null;

        try {
            $this->engine->approve($instance, $request->user(), $comment);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['message' => 'Đã phê duyệt thành công.']);
    }

    /**
     * POST /api/workflow/instances/{id}/reject
     *
     * Body: { "comment": "Lý do từ chối" } (bắt buộc)
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'comment' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $instance = WorkflowInstance::findOrFail($id);

        try {
            $this->engine->reject($instance, $request->user(), $request->string('comment')->value());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['message' => 'Đã từ chối.']);
    }

    /**
     * POST /api/workflow/instances/{id}/cancel
     *
     * Body: { "reason": "Lý do hủy" } (bắt buộc)
     * Chỉ submitter hoặc admin mới được hủy.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $instance = WorkflowInstance::findOrFail($id);
        $userId = (string) $request->user()->getKey();

        // Authorization: chỉ submitter hoặc admin
        $isAdmin = $request->user()->hasRole('admin') ?? false;
        $isSubmitter = $instance->isSubmittedBy($userId);

        if (! $isSubmitter && ! $isAdmin) {
            return response()->json(['message' => 'Chỉ người submit hoặc admin mới có thể hủy workflow.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->engine->cancel($instance, $request->user(), $request->string('reason')->value());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['message' => 'Đã hủy workflow.']);
    }
}

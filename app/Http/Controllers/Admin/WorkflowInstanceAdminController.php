<?php

declare(strict_types=1);

namespace Omnify\Workflow\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowInstance;

class WorkflowInstanceAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $pagesPath = config('workflow.admin.pages_path', 'admin/workflow');

        $sortField = $request->input('sort', '-submitted_at');
        $sortDirection = 'asc';

        if (str_starts_with($sortField, '-')) {
            $sortField = substr($sortField, 1);
            $sortDirection = 'desc';
        }

        $allowedSorts = ['status', 'current_step', 'submitted_at', 'completed_at'];
        if (! in_array($sortField, $allowedSorts)) {
            $sortField = 'submitted_at';
            $sortDirection = 'desc';
        }

        $instances = WorkflowInstance::query()
            ->with('definition:id,name,slug')
            ->when(
                $request->input('q'),
                fn ($q, $search) => $q->where('workflowable_type', 'like', "%{$search}%")
            )
            ->when(
                $request->input('filter.status'),
                fn ($q, $status) => $q->where('status', $status)
            )
            ->when(
                $request->input('filter.definition_id'),
                fn ($q, $defId) => $q->where('workflow_definition_id', $defId)
            )
            ->when(
                $request->input('filter.submitted_at_from'),
                fn ($q, $from) => $q->whereDate('submitted_at', '>=', $from)
            )
            ->when(
                $request->input('filter.submitted_at_to'),
                fn ($q, $to) => $q->whereDate('submitted_at', '<=', $to)
            )
            ->orderBy($sortField, $sortDirection)
            ->paginate(15)
            ->withQueryString();

        $definitions = WorkflowDefinition::orderBy('name')
            ->get(['id', 'name', 'slug']);

        return Inertia::render("{$pagesPath}/instances/index", [
            'instances' => [
                'data' => $instances->items(),
                'meta' => [
                    'current_page' => $instances->currentPage(),
                    'last_page' => $instances->lastPage(),
                    'per_page' => $instances->perPage(),
                    'total' => $instances->total(),
                ],
                'links' => [
                    'first' => $instances->url(1),
                    'last' => $instances->url($instances->lastPage()),
                    'prev' => $instances->previousPageUrl(),
                    'next' => $instances->nextPageUrl(),
                ],
            ],
            'definitions' => $definitions,
            'filters' => [
                'q' => $request->input('q'),
                'sort' => $request->input('sort'),
                'filter' => $request->input('filter'),
            ],
        ]);
    }

    public function show(string $id): Response
    {
        $pagesPath = config('workflow.admin.pages_path', 'admin/workflow');

        $instance = WorkflowInstance::with([
            'definition.steps',
            'stepApprovals' => fn ($q) => $q->orderBy('step_order')->orderBy('created_at'),
            'history' => fn ($q) => $q->orderBy('created_at'),
        ])->findOrFail($id);

        return Inertia::render("{$pagesPath}/instances/show", [
            'instance' => $instance,
        ]);
    }
}

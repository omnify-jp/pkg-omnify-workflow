<?php

declare(strict_types=1);

namespace Omnify\Workflow\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Omnify\Workflow\Http\Requests\StoreWorkflowDefinitionRequest;
use Omnify\Workflow\Http\Requests\UpdateWorkflowDefinitionRequest;
use Omnify\SsoClient\Models\Role;
use Omnify\SsoClient\Models\User;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;

class WorkflowDefinitionAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $pagesPath = config('workflow.admin.pages_path', 'admin/workflow');

        $sortField = $request->input('sort', 'name');
        $sortDirection = 'asc';

        if (str_starts_with($sortField, '-')) {
            $sortField = substr($sortField, 1);
            $sortDirection = 'desc';
        }

        $allowedSorts = ['name', 'slug', 'is_active', 'created_at'];
        if (! in_array($sortField, $allowedSorts)) {
            $sortField = 'name';
            $sortDirection = 'asc';
        }

        $definitions = WorkflowDefinition::query()
            ->withCount('steps')
            ->when(
                $request->input('q'),
                fn ($q, $search) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
            )
            ->when(
                $request->input('filter.is_active') !== null,
                fn ($q) => $q->where('is_active', $request->boolean('filter.is_active'))
            )
            ->orderBy($sortField, $sortDirection)
            ->paginate(15)
            ->withQueryString();

        $roles = class_exists(Role::class)
            ? Role::select('id', 'slug', 'name', 'description', 'level')
                ->orderBy('level', 'desc')
                ->get()
            : collect();

        $users = class_exists(User::class)
            ? User::select('id', 'name', 'email')->orderBy('name')->get()
            : collect();

        return Inertia::render("{$pagesPath}/definitions/index", [
            'definitions' => [
                'data' => $definitions->items(),
                'meta' => [
                    'current_page' => $definitions->currentPage(),
                    'last_page' => $definitions->lastPage(),
                    'per_page' => $definitions->perPage(),
                    'total' => $definitions->total(),
                ],
                'links' => [
                    'first' => $definitions->url(1),
                    'last' => $definitions->url($definitions->lastPage()),
                    'prev' => $definitions->previousPageUrl(),
                    'next' => $definitions->nextPageUrl(),
                ],
            ],
            'filters' => [
                'q' => $request->input('q'),
                'sort' => $request->input('sort'),
                'filter' => $request->input('filter'),
            ],
            'roles' => $roles,
            'users' => $users,
        ]);
    }

    public function store(StoreWorkflowDefinitionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $definition = DB::transaction(function () use ($data, $request) {
            $definition = WorkflowDefinition::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
                'console_organization_id' => $request->user()?->getAttribute('console_organization_id'),
            ]);

            foreach ($data['steps'] as $i => $step) {
                WorkflowDefinitionStep::create([
                    'workflow_definition_id' => $definition->id,
                    'step_order' => $i + 1,
                    'name' => $step['name'],
                    'approver_role' => $step['approver_role'] ?? null,
                    'approver_user_ids' => ! empty($step['approver_user_ids']) ? $step['approver_user_ids'] : null,
                    'type' => $step['type'],
                    'deadline_hours' => $step['deadline_hours'] ?? null,
                    'escalate_to_role' => $step['escalate_to_role'] ?? null,
                ]);
            }

            return $definition;
        });

        return response()->json(['message' => __('Workflow definition created.'), 'id' => $definition->id], 201);
    }

    public function update(UpdateWorkflowDefinitionRequest $request, WorkflowDefinition $definition): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $definition): void {
            $definition->update([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ]);

            $definition->steps()->delete();

            foreach ($data['steps'] as $i => $step) {
                WorkflowDefinitionStep::create([
                    'workflow_definition_id' => $definition->id,
                    'step_order' => $i + 1,
                    'name' => $step['name'],
                    'approver_role' => $step['approver_role'] ?? null,
                    'approver_user_ids' => ! empty($step['approver_user_ids']) ? $step['approver_user_ids'] : null,
                    'type' => $step['type'],
                    'deadline_hours' => $step['deadline_hours'] ?? null,
                    'escalate_to_role' => $step['escalate_to_role'] ?? null,
                ]);
            }
        });

        return response()->json(['message' => __('Workflow definition updated.')]);
    }

    public function destroy(WorkflowDefinition $definition): JsonResponse
    {
        if ($definition->instances()->where('status', 'pending')->exists()) {
            return response()->json([
                'message' => __('Cannot delete: this definition has active workflow instances.'),
            ], 422);
        }

        $definition->steps()->delete();
        $definition->delete();

        return response()->json(['message' => __('Workflow definition deleted.')]);
    }
}

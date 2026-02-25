<?php

declare(strict_types=1);

namespace Omnify\Workflow\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Omnify\Workflow\Http\Requests\StoreWorkflowDefinitionRequest;
use Omnify\Workflow\Http\Requests\UpdateWorkflowDefinitionRequest;
use Omnify\Workflow\Models\WorkflowDefinition;
use Omnify\Workflow\Models\WorkflowDefinitionStep;

/**
 * WorkflowDefinitionController — CRUD cho workflow definitions.
 *
 * Routes (prefix tùy config, mặc định: /api/workflow):
 *   POST   /definitions              → Tạo definition + steps
 *   PUT    /definitions/{definition} → Update definition, replace steps
 *   DELETE /definitions/{definition} → Xóa (chặn nếu có pending instances)
 */
class WorkflowDefinitionController extends Controller
{
    public function store(StoreWorkflowDefinitionRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request): void {
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
                    'type' => $step['type'],
                    'deadline_hours' => $step['deadline_hours'] ?? null,
                    'escalate_to_role' => $step['escalate_to_role'] ?? null,
                ]);
            }
        });

        return redirect()->back();
    }

    public function update(UpdateWorkflowDefinitionRequest $request, WorkflowDefinition $definition): RedirectResponse
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
                    'type' => $step['type'],
                    'deadline_hours' => $step['deadline_hours'] ?? null,
                    'escalate_to_role' => $step['escalate_to_role'] ?? null,
                ]);
            }
        });

        return redirect()->back();
    }

    public function destroy(WorkflowDefinition $definition): RedirectResponse
    {
        if ($definition->instances()->where('status', 'pending')->exists()) {
            return redirect()->back()->withErrors([
                'definition' => __('Cannot delete: this definition has active workflow instances.'),
            ]);
        }

        $definition->steps()->delete();
        $definition->delete();

        return redirect()->back();
    }
}

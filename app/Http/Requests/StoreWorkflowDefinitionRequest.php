<?php

declare(strict_types=1);

namespace Omnify\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'slug' => [
                'required',
                'string',
                'max:200',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('workflow_definitions', 'slug')
                    ->where('console_organization_id', $this->user()?->getAttribute('console_organization_id')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.name' => ['required', 'string', 'max:200'],
            'steps.*.approver_role' => ['nullable', 'string', 'max:100'],
            'steps.*.approver_user_ids' => ['nullable', 'array'],
            'steps.*.approver_user_ids.*' => ['uuid'],
            'steps.*.type' => ['required', 'string', Rule::in(['sequential', 'any_of', 'parallel'])],
            'steps.*.deadline_hours' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'steps.*.escalate_to_role' => ['nullable', 'string', 'max:100'],
        ];
    }
}

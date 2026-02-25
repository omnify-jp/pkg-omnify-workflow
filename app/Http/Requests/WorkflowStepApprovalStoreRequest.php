<?php

/**
 * WorkflowStepApproval Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\OmnifyBase\WorkflowStepApprovalStoreRequestBase;

/**
 * WorkflowStepApprovalStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class WorkflowStepApprovalStoreRequest extends WorkflowStepApprovalStoreRequestBase
{
    //
}

/* ── Workflow Status & Step Types ─────────────────── */

export type WorkflowStatusValue = 'draft' | 'pending' | 'approved' | 'rejected' | 'cancelled';

export type WorkflowStepApprovalStatusValue = 'pending' | 'approved' | 'rejected' | 'delegated' | 'expired';

export type WorkflowStepTypeValue = 'sequential' | 'any_of' | 'parallel';

/* ── Status color mapping (matches PHP Enum::color()) ─ */

export const workflowStatusColor: Record<WorkflowStatusValue, string> = {
    draft: 'default',
    pending: 'warning',
    approved: 'success',
    rejected: 'error',
    cancelled: 'default',
};

export const approvalStatusColor: Record<WorkflowStepApprovalStatusValue, string> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'error',
    delegated: 'processing',
    expired: 'warning',
};

/* ── Role & User Options (for dropdowns) ─────────── */

export type RoleOption = {
    slug: string;
    name: string;
    description: string | null;
    level: number;
};

export type UserOption = {
    id: string;
    name: string;
    email: string;
};

/* ── Entities ────────────────────────────────────── */

export type WorkflowDefinitionStep = {
    id: string;
    workflow_definition_id: string;
    step_order: number;
    name: string;
    description: string | null;
    approver_role: string | null;
    approver_user_ids: string[] | null;
    type: WorkflowStepTypeValue;
    deadline_hours: number | null;
    escalate_to_role: string | null;
};

export type WorkflowDefinition = {
    id: string;
    slug: string;
    console_organization_id: string | null;
    name: string;
    description: string | null;
    is_active: boolean;
    config: Record<string, unknown> | null;
    steps_count?: number;
    steps?: WorkflowDefinitionStep[];
    created_at: string;
    updated_at: string;
};

export type WorkflowInstance = {
    id: string;
    workflow_definition_id: string | null;
    workflowable_type: string;
    workflowable_id: string;
    status: WorkflowStatusValue;
    current_step: number;
    submitted_by: string;
    submitted_at: string;
    completed_at: string | null;
    metadata: Record<string, unknown> | null;
    definition?: WorkflowDefinition | null;
    step_approvals?: WorkflowStepApproval[];
    history?: WorkflowHistory[];
    created_at: string;
    updated_at: string;
};

export type WorkflowStepApproval = {
    id: string;
    workflow_instance_id: string;
    workflow_definition_step_id: string | null;
    step_order: number;
    approver_id: string | null;
    approver_role: string | null;
    status: WorkflowStepApprovalStatusValue;
    comment: string | null;
    decided_at: string | null;
    deadline_at: string | null;
    notified_at: string | null;
    created_at: string;
    updated_at: string;
};

export type WorkflowHistory = {
    id: string;
    workflow_instance_id: string;
    actor_id: string | null;
    action: string;
    from_status: WorkflowStatusValue | null;
    to_status: WorkflowStatusValue | null;
    step_order: number | null;
    comment: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
};

/* ── Pagination ──────────────────────────────────── */

export type PaginatedResponse<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
};

/* ── Form Data ───────────────────────────────────── */

export type DefinitionStepFormData = {
    name: string;
    type: WorkflowStepTypeValue;
    approver_role: string | null;
    approver_user_ids?: string[];
    deadline_hours: number | null;
    escalate_to_role: string | null;
};

export type DefinitionFormData = {
    name: string;
    slug: string;
    description: string | null;
    is_active: boolean;
    steps: DefinitionStepFormData[];
};

/* ── Filters ─────────────────────────────────────── */

export type WorkflowFilters = {
    q?: string | null;
    sort?: string | null;
    filter?: Record<string, string> | null;
};

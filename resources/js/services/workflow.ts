import { api } from '@omnify-core/services/api';
import type { DefinitionFormData } from '@omnify-workflow/types/workflow';

const ADMIN_PREFIX = '/settings/workflow';
const API_PREFIX = '/api/workflow';

export const workflowService = {
    /* ── Definitions (Admin CRUD) ──────────────────── */

    storeDefinition: (data: DefinitionFormData) =>
        api.post(`${ADMIN_PREFIX}/definitions`, data),

    updateDefinition: (id: string, data: DefinitionFormData) =>
        api.put(`${ADMIN_PREFIX}/definitions/${id}`, data),

    deleteDefinition: (id: string) =>
        api.delete(`${ADMIN_PREFIX}/definitions/${id}`),

    /* ── Instances (API actions) ───────────────────── */

    approveInstance: (id: string, comment?: string) =>
        api.post(`${API_PREFIX}/instances/${id}/approve`, { comment }),

    rejectInstance: (id: string, comment: string) =>
        api.post(`${API_PREFIX}/instances/${id}/reject`, { comment }),

    cancelInstance: (id: string, reason: string) =>
        api.post(`${API_PREFIX}/instances/${id}/cancel`, { reason }),
};

import { router } from '@inertiajs/react';
import {
    App,
    Button,
    Card,
    Descriptions,
    Flex,
    Form,
    Input,
    Modal,
    Steps,
    Table,
    Tag,
    Timeline,
    Typography,
    theme,
} from 'antd';
import type { TableColumnsType } from 'antd';
import dayjs from 'dayjs';
import { ArrowLeft, Check, X, Ban } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useMutation } from '@tanstack/react-query';
import { PageContainer } from '@omnify-core/components/page-container';
import { workflowService } from '@omnify-workflow/services/workflow';
import type {
    WorkflowHistory,
    WorkflowInstance,
    WorkflowStepApproval,
    WorkflowStepApprovalStatusValue,
    WorkflowStatusValue,
} from '@omnify-workflow/types/workflow';
import { approvalStatusColor, workflowStatusColor } from '@omnify-workflow/types/workflow';

/* ── Types ─────────────────────────────────────────── */

type Props = {
    instance: WorkflowInstance;
};

/* ── Helpers ────────────────────────────────────────── */

function statusLabel(status: WorkflowStatusValue): string {
    const labels: Record<WorkflowStatusValue, string> = {
        draft: 'Draft',
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
        cancelled: 'Cancelled',
    };
    return labels[status] ?? status;
}

function approvalStatusLabel(status: WorkflowStepApprovalStatusValue): string {
    const labels: Record<WorkflowStepApprovalStatusValue, string> = {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
        delegated: 'Delegated',
        expired: 'Expired',
    };
    return labels[status] ?? status;
}

function shortId(id: string): string {
    return id.slice(0, 8);
}

function formatDate(date: string | null | undefined): string {
    if (!date) return '—';
    return dayjs(date).format('YYYY-MM-DD HH:mm');
}

function historyColor(action: string): string {
    const map: Record<string, string> = {
        submitted: 'blue',
        step_started: 'blue',
        step_approved: 'green',
        step_completed: 'green',
        approved: 'green',
        rejected: 'red',
        cancelled: 'gray',
        escalated: 'orange',
        expired: 'orange',
    };
    return map[action] ?? 'gray';
}

function actionLabel(action: string): string {
    const map: Record<string, string> = {
        submitted: 'Workflow Submitted',
        step_started: 'Step Started',
        step_approved: 'Step Approved',
        step_completed: 'Step Completed',
        approved: 'Workflow Approved',
        rejected: 'Workflow Rejected',
        cancelled: 'Workflow Cancelled',
        escalated: 'Escalated',
        expired: 'Expired',
    };
    return map[action] ?? action;
}

/* ── Main Page ─────────────────────────────────────── */

export default function WorkflowInstanceShow({ instance }: Props) {
    const { t } = useTranslation();
    const { token } = theme.useToken();
    const { message } = App.useApp();

    const [approveModalOpen, setApproveModalOpen] = useState(false);
    const [rejectModalOpen, setRejectModalOpen] = useState(false);
    const [cancelModalOpen, setCancelModalOpen] = useState(false);
    const [rejectForm] = Form.useForm<{ comment: string }>();
    const [cancelForm] = Form.useForm<{ reason: string }>();

    const isPending = instance.status === 'pending';

    const breadcrumbs = [
        { title: t('workflow.instances.title', 'Workflow Instances'), href: '/settings/workflow/instances' },
        { title: shortId(instance.id), href: `/settings/workflow/instances/${instance.id}` },
    ];

    /* ── Mutations ──────────────────────────────── */

    const approveMutation = useMutation({
        mutationFn: (comment?: string) => workflowService.approveInstance(instance.id, comment),
        onSuccess: () => {
            message.success(t('workflow.instances.approved', 'Workflow approved.'));
            setApproveModalOpen(false);
            router.reload();
        },
        onError: () => {
            message.error(t('workflow.instances.approveError', 'Failed to approve.'));
        },
    });

    const rejectMutation = useMutation({
        mutationFn: (comment: string) => workflowService.rejectInstance(instance.id, comment),
        onSuccess: () => {
            message.success(t('workflow.instances.rejected', 'Workflow rejected.'));
            setRejectModalOpen(false);
            router.reload();
        },
        onError: () => {
            message.error(t('workflow.instances.rejectError', 'Failed to reject.'));
        },
    });

    const cancelMutation = useMutation({
        mutationFn: (reason: string) => workflowService.cancelInstance(instance.id, reason),
        onSuccess: () => {
            message.success(t('workflow.instances.cancelled', 'Workflow cancelled.'));
            setCancelModalOpen(false);
            router.reload();
        },
        onError: () => {
            message.error(t('workflow.instances.cancelError', 'Failed to cancel.'));
        },
    });

    /* ── Steps progress ────────────────────────── */

    const definitionSteps = instance.definition?.steps ?? [];
    const stepsItems = definitionSteps.map((step) => {
        let stepStatus: 'wait' | 'process' | 'finish' | 'error' = 'wait';
        if (step.step_order < instance.current_step) stepStatus = 'finish';
        else if (step.step_order === instance.current_step) {
            if (instance.status === 'rejected') stepStatus = 'error';
            else if (instance.status === 'approved') stepStatus = 'finish';
            else stepStatus = 'process';
        }
        return {
            title: step.name,
            description: step.approver_role ? `Role: ${step.approver_role}` : undefined,
            status: stepStatus,
        };
    });

    /* ── Approval table ────────────────────────── */

    const approvalColumns: TableColumnsType<WorkflowStepApproval> = [
        {
            title: t('workflow.approvals.step', 'Step'),
            dataIndex: 'step_order',
            key: 'step_order',
            width: 70,
            align: 'center',
        },
        {
            title: t('workflow.approvals.role', 'Role'),
            dataIndex: 'approver_role',
            key: 'approver_role',
            render: (value) => value ?? '—',
        },
        {
            title: t('workflow.approvals.approver', 'Approver'),
            key: 'approver_id',
            render: (_, record) => record.approver_id ? shortId(record.approver_id) : t('workflow.approvals.anyRole', 'Any (role-based)'),
        },
        {
            title: t('workflow.approvals.status', 'Status'),
            key: 'status',
            render: (_, record) => (
                <Tag color={approvalStatusColor[record.status]}>
                    {approvalStatusLabel(record.status)}
                </Tag>
            ),
        },
        {
            title: t('workflow.approvals.comment', 'Comment'),
            dataIndex: 'comment',
            key: 'comment',
            render: (value) => value ?? '—',
        },
        {
            title: t('workflow.approvals.decidedAt', 'Decided'),
            key: 'decided_at',
            render: (_, record) => formatDate(record.decided_at),
        },
        {
            title: t('workflow.approvals.deadline', 'Deadline'),
            key: 'deadline_at',
            render: (_, record) => formatDate(record.deadline_at),
        },
    ];

    /* ── History timeline ──────────────────────── */

    const historyItems = (instance.history ?? []).map((h: WorkflowHistory) => ({
        color: historyColor(h.action),
        children: (
            <Flex vertical gap={token.paddingXXS / 2}>
                <Flex gap="small" align="center">
                    <Typography.Text strong>{actionLabel(h.action)}</Typography.Text>
                    {h.step_order && (
                        <Tag>{t('workflow.history.step', 'Step')} {h.step_order}</Tag>
                    )}
                </Flex>
                {h.comment && (
                    <Typography.Text type="secondary">{h.comment}</Typography.Text>
                )}
                <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                    {formatDate(h.created_at)}
                    {h.actor_id && ` · ${shortId(h.actor_id)}`}
                </Typography.Text>
            </Flex>
        ),
    }));

    return (
        <PageContainer
            title={`${t('workflow.instances.detail', 'Workflow Instance')} ${shortId(instance.id)}`}
            breadcrumbs={breadcrumbs}
            extra={
                <Button
                    icon={<ArrowLeft size={16} />}
                    onClick={() => router.visit('/settings/workflow/instances')}
                >
                    {t('common.back', 'Back')}
                </Button>
            }
        >
            <Flex vertical gap="large">
                {/* ── Instance Info ───────────────────── */}
                <Card>
                    <Descriptions column={{ xs: 1, sm: 2, lg: 3 }}>
                        <Descriptions.Item label={t('workflow.instances.status', 'Status')}>
                            <Tag color={workflowStatusColor[instance.status]}>
                                {statusLabel(instance.status)}
                            </Tag>
                        </Descriptions.Item>
                        <Descriptions.Item label={t('workflow.instances.definition', 'Definition')}>
                            {instance.definition?.name ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label={t('workflow.instances.type', 'Type')}>
                            {instance.workflowable_type}
                        </Descriptions.Item>
                        <Descriptions.Item label={t('workflow.instances.submittedBy', 'Submitted By')}>
                            {shortId(instance.submitted_by)}
                        </Descriptions.Item>
                        <Descriptions.Item label={t('workflow.instances.submittedAt', 'Submitted')}>
                            {formatDate(instance.submitted_at)}
                        </Descriptions.Item>
                        <Descriptions.Item label={t('workflow.instances.completedAt', 'Completed')}>
                            {formatDate(instance.completed_at)}
                        </Descriptions.Item>
                        <Descriptions.Item label={t('workflow.instances.currentStep', 'Current Step')}>
                            {instance.current_step}
                        </Descriptions.Item>
                    </Descriptions>
                </Card>

                {/* ── Actions ─────────────────────────── */}
                {isPending && (
                    <Flex gap="small">
                        <Button
                            type="primary"
                            icon={<Check size={16} />}
                            onClick={() => setApproveModalOpen(true)}
                        >
                            {t('workflow.instances.approve', 'Approve')}
                        </Button>
                        <Button
                            danger
                            icon={<X size={16} />}
                            onClick={() => setRejectModalOpen(true)}
                        >
                            {t('workflow.instances.reject', 'Reject')}
                        </Button>
                        <Button
                            icon={<Ban size={16} />}
                            onClick={() => setCancelModalOpen(true)}
                        >
                            {t('workflow.instances.cancel', 'Cancel')}
                        </Button>
                    </Flex>
                )}

                {/* ── Steps Progress ──────────────────── */}
                {stepsItems.length > 0 && (
                    <Card title={t('workflow.instances.stepsProgress', 'Steps Progress')}>
                        <Steps items={stepsItems} />
                    </Card>
                )}

                {/* ── Approvals Table ─────────────────── */}
                <Card title={t('workflow.approvals.title', 'Approvals')}>
                    <Table
                        dataSource={instance.step_approvals ?? []}
                        columns={approvalColumns}
                        rowKey="id"
                        pagination={false}
                        size="small"
                    />
                </Card>

                {/* ── History Timeline ────────────────── */}
                {historyItems.length > 0 && (
                    <Card title={t('workflow.history.title', 'History')}>
                        <Timeline items={historyItems} />
                    </Card>
                )}
            </Flex>

            {/* ── Approve Modal ───────────────────── */}
            <Modal
                title={t('workflow.instances.approveTitle', 'Approve Workflow')}
                open={approveModalOpen}
                onCancel={() => setApproveModalOpen(false)}
                onOk={() => approveMutation.mutate(undefined)}
                confirmLoading={approveMutation.isPending}
                okText={t('workflow.instances.approve', 'Approve')}
            >
                <Typography.Text>
                    {t('workflow.instances.approveConfirm', 'Are you sure you want to approve this workflow step?')}
                </Typography.Text>
            </Modal>

            {/* ── Reject Modal ────────────────────── */}
            <Modal
                title={t('workflow.instances.rejectTitle', 'Reject Workflow')}
                open={rejectModalOpen}
                onCancel={() => setRejectModalOpen(false)}
                onOk={() => rejectForm.validateFields().then((data) => rejectMutation.mutate(data.comment))}
                confirmLoading={rejectMutation.isPending}
                okText={t('workflow.instances.reject', 'Reject')}
                okButtonProps={{ danger: true }}
            >
                <Form form={rejectForm} layout="vertical">
                    <Form.Item
                        name="comment"
                        label={t('workflow.instances.rejectReason', 'Reason for rejection')}
                        rules={[{ required: true, message: t('validation.required', 'This field is required.') }]}
                    >
                        <Input.TextArea rows={3} placeholder={t('workflow.instances.rejectPlaceholder', 'Please provide a reason...')} />
                    </Form.Item>
                </Form>
            </Modal>

            {/* ── Cancel Modal ────────────────────── */}
            <Modal
                title={t('workflow.instances.cancelTitle', 'Cancel Workflow')}
                open={cancelModalOpen}
                onCancel={() => setCancelModalOpen(false)}
                onOk={() => cancelForm.validateFields().then((data) => cancelMutation.mutate(data.reason))}
                confirmLoading={cancelMutation.isPending}
                okText={t('workflow.instances.cancel', 'Cancel Workflow')}
                okButtonProps={{ danger: true }}
            >
                <Form form={cancelForm} layout="vertical">
                    <Form.Item
                        name="reason"
                        label={t('workflow.instances.cancelReason', 'Reason for cancellation')}
                        rules={[{ required: true, message: t('validation.required', 'This field is required.') }]}
                    >
                        <Input.TextArea rows={3} placeholder={t('workflow.instances.cancelPlaceholder', 'Please provide a reason...')} />
                    </Form.Item>
                </Form>
            </Modal>
        </PageContainer>
    );
}

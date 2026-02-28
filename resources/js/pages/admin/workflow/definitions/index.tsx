import { router } from '@inertiajs/react';
import { App, Button, Card, Drawer, Dropdown, Flex, Form, Input, Radio, Select, Switch, Table, Tag, Typography } from 'antd';
import type { TableColumnsType, TableProps } from 'antd';
import { isAxiosError } from 'axios';
import { Ellipsis, Minus, Plus, PlusCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useMutation } from '@tanstack/react-query';
import { PageContainer } from '@omnify-core/components/page-container';
import {
    buildFilterParams,
    FilterAdvancedButton,
    FilterChips,
    FilterDrawer,
    Filters,
    FilterSearch,
} from '@omnify-core/components/filters';
import { workflowService } from '@omnify-workflow/services/workflow';
import type {
    DefinitionFormData,
    DefinitionStepFormData,
    PaginatedResponse,
    RoleOption,
    UserOption,
    WorkflowDefinition,
    WorkflowFilters,
    WorkflowStepTypeValue,
} from '@omnify-workflow/types/workflow';

/* ── Types ─────────────────────────────────────────── */

type Props = {
    definitions: PaginatedResponse<WorkflowDefinition>;
    filters: WorkflowFilters;
    roles: RoleOption[];
    users: UserOption[];
};

/* ── Helpers ────────────────────────────────────────── */

function parseSortParam(sort: string | null | undefined): { field: string; order: 'ascend' | 'descend' } {
    if (typeof sort !== 'string' || sort === '') return { field: 'name', order: 'ascend' };
    if (sort.startsWith('-')) return { field: sort.slice(1), order: 'descend' };
    return { field: sort, order: 'ascend' };
}

function toSlug(name: string): string {
    return name
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

const STEP_TYPE_OPTIONS: { value: WorkflowStepTypeValue; label: string }[] = [
    { value: 'sequential', label: 'Sequential (any one)' },
    { value: 'any_of', label: 'Any Of (any one)' },
    { value: 'parallel', label: 'Parallel (all must approve)' },
];

const DEFAULT_STEP: DefinitionStepFormData = {
    name: '',
    type: 'sequential',
    approver_role: null,
    deadline_hours: null,
    escalate_to_role: null,
};

/* ── Approver Fields (Radio toggle + conditional select) ── */

type ApproverFieldsProps = {
    restField: { fieldKey?: number };
    name: number;
    roles: RoleOption[];
    users: UserOption[];
    form: ReturnType<typeof Form.useForm<DefinitionFormData>>[0];
    t: ReturnType<typeof useTranslation>['t'];
};

function ApproverFields({ restField, name, roles, users, form, t }: ApproverFieldsProps) {
    const currentValues = Form.useWatch(['steps', name], form);
    const approverMode = useMemo(() => {
        if (currentValues?.approver_user_ids && currentValues.approver_user_ids.length > 0) return 'users';
        return 'role';
    }, [currentValues?.approver_user_ids]);

    const [mode, setMode] = useState<'role' | 'users'>(approverMode);

    useEffect(() => {
        setMode(approverMode);
    }, [approverMode]);

    const handleModeChange = (newMode: 'role' | 'users') => {
        setMode(newMode);
        if (newMode === 'users') {
            form.setFieldValue(['steps', name, 'approver_role'], null);
        } else {
            form.setFieldValue(['steps', name, 'approver_user_ids'], null);
        }
    };

    const userOptions = useMemo(
        () => users.map((u) => ({ value: u.id, label: `${u.name} (${u.email})` })),
        [users],
    );

    const roleOptions = useMemo(
        () => roles.map((r) => ({ value: r.slug, label: r.name })),
        [roles],
    );

    return (
        <>
            <Radio.Group
                value={mode}
                onChange={(e) => handleModeChange(e.target.value)}
                optionType="button"
                size="small"
                options={[
                    { value: 'role', label: t('workflow.definitions.byRole', 'By Role') },
                    { value: 'users', label: t('workflow.definitions.specificUsers', 'Specific Users') },
                ]}
            />

            {mode === 'role' ? (
                <Form.Item
                    {...restField}
                    name={[name, 'approver_role']}
                    style={{ marginBottom: 0 }}
                >
                    <Select
                        allowClear
                        showSearch
                        placeholder={t('workflow.definitions.selectRole', 'Select role...')}
                        optionFilterProp="label"
                        options={roleOptions}
                    />
                </Form.Item>
            ) : (
                <Form.Item
                    {...restField}
                    name={[name, 'approver_user_ids']}
                    style={{ marginBottom: 0 }}
                >
                    <Select
                        mode="multiple"
                        allowClear
                        showSearch
                        placeholder={t('workflow.definitions.selectUsers', 'Select users...')}
                        optionFilterProp="label"
                        options={userOptions}
                    />
                </Form.Item>
            )}
        </>
    );
}

/* ── Definition Form Drawer ────────────────────────── */

type FormDrawerProps = {
    open: boolean;
    onClose: () => void;
    definition: WorkflowDefinition | null;
    roles: RoleOption[];
    users: UserOption[];
};

function DefinitionFormDrawer({ open, onClose, definition, roles, users }: FormDrawerProps) {
    const { t } = useTranslation();
    const { message } = App.useApp();
    const [form] = Form.useForm<DefinitionFormData>();
    const isEdit = !!definition;

    useEffect(() => {
        if (!open) return;

        if (isEdit && definition.steps) {
            form.setFieldsValue({
                name: definition.name,
                slug: definition.slug,
                description: definition.description ?? '',
                is_active: definition.is_active,
                steps: definition.steps.map((s) => ({
                    name: s.name,
                    type: s.type,
                    approver_role: s.approver_role,
                    approver_user_ids: s.approver_user_ids ?? undefined,
                    deadline_hours: s.deadline_hours,
                    escalate_to_role: s.escalate_to_role,
                })),
            });
        } else {
            form.setFieldsValue({
                name: '',
                slug: '',
                description: '',
                is_active: true,
                steps: [{ ...DEFAULT_STEP }],
            });
        }
    }, [open, definition, form, isEdit]);

    const mutation = useMutation({
        mutationFn: (data: DefinitionFormData) => {
            if (isEdit) {
                return workflowService.updateDefinition(definition.id, data);
            }
            return workflowService.storeDefinition(data);
        },
        onSuccess: () => {
            message.success(
                isEdit
                    ? t('workflow.definitions.updated', 'Workflow definition updated.')
                    : t('workflow.definitions.created', 'Workflow definition created.'),
            );
            onClose();
            router.reload();
        },
        onError: (error) => {
            if (isAxiosError(error) && error.response?.status === 422) {
                const serverErrors = error.response.data.errors as Record<string, string[]>;
                form.setFields(
                    Object.entries(serverErrors).map(([key, messages]) => ({
                        name: key.split('.') as unknown as keyof DefinitionFormData,
                        errors: messages,
                    })),
                );
            }
        },
    });

    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const name = e.target.value;
        if (!isEdit) {
            const currentSlug = form.getFieldValue('slug') as string;
            const currentName = form.getFieldValue('name') as string;
            if (currentSlug === '' || currentSlug === toSlug(currentName)) {
                form.setFieldValue('slug', toSlug(name));
            }
        }
    };

    return (
        <Drawer
            title={isEdit
                ? t('workflow.definitions.edit', 'Edit Workflow Definition')
                : t('workflow.definitions.create', 'Create Workflow Definition')}
            placement="right"
            size={560}
            open={open}
            onClose={onClose}
            footer={
                <Flex justify="end" gap={8}>
                    <Button onClick={onClose}>
                        {t('common.cancel', 'Cancel')}
                    </Button>
                    <Button
                        type="primary"
                        loading={mutation.isPending}
                        onClick={() => form.validateFields().then((data) => mutation.mutate(data))}
                    >
                        {isEdit
                            ? t('common.save', 'Save Changes')
                            : t('workflow.definitions.createDef', 'Create Definition')}
                    </Button>
                </Flex>
            }
        >
            <Form form={form} layout="vertical">
                <Form.Item
                    name="name"
                    label={t('workflow.definitions.name', 'Name')}
                    rules={[
                        { required: true, message: t('validation.required', 'This field is required.') },
                        { max: 200 },
                    ]}
                >
                    <Input
                        onChange={handleNameChange}
                        placeholder={t('workflow.definitions.namePlaceholder', 'e.g. Leave Approval')}
                    />
                </Form.Item>

                <Form.Item
                    name="slug"
                    label={t('workflow.definitions.slug', 'Slug')}
                    rules={[
                        { required: true, message: t('validation.required', 'This field is required.') },
                        { max: 200 },
                        { pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/, message: t('validation.slug', 'Only lowercase letters, numbers, and hyphens.') },
                    ]}
                    extra={t('workflow.definitions.slugDesc', 'URL-friendly identifier. Auto-generated from name.')}
                >
                    <Input placeholder="leave-approval" />
                </Form.Item>

                <Form.Item
                    name="description"
                    label={t('workflow.definitions.description', 'Description')}
                    rules={[{ max: 1000 }]}
                >
                    <Input.TextArea
                        rows={2}
                        placeholder={t('workflow.definitions.descriptionPlaceholder', 'Optional description...')}
                    />
                </Form.Item>

                <Form.Item
                    name="is_active"
                    label={t('workflow.definitions.isActive', 'Active')}
                    valuePropName="checked"
                >
                    <Switch />
                </Form.Item>

                {/* ── Steps ──────────────────────────── */}
                <Typography.Text strong style={{ display: 'block', marginBottom: 12 }}>
                    {t('workflow.definitions.steps', 'Approval Steps')}
                </Typography.Text>

                <Form.List
                    name="steps"
                    rules={[
                        {
                            validator: async (_, steps) => {
                                if (!steps || steps.length < 1) {
                                    return Promise.reject(new Error(t('workflow.definitions.stepsMin', 'At least one step is required.')));
                                }
                            },
                        },
                    ]}
                >
                    {(fields, { add, remove }, { errors }) => (
                        <Flex vertical gap={12}>
                            {fields.map(({ key, name, ...restField }) => (
                                <Card
                                    key={key}
                                    size="small"
                                    title={`${t('workflow.definitions.step', 'Step')} ${name + 1}`}
                                    extra={
                                        fields.length > 1 && (
                                            <Button
                                                type="text"
                                                size="small"
                                                danger
                                                icon={<Minus size={14} />}
                                                onClick={() => remove(name)}
                                            />
                                        )
                                    }
                                >
                                    <Form.Item
                                        {...restField}
                                        name={[name, 'name']}
                                        label={t('workflow.definitions.stepName', 'Step Name')}
                                        tooltip={t('workflow.definitions.stepNameHelp', 'Display name for this approval step.')}
                                        rules={[{ required: true, message: t('validation.required', 'This field is required.') }, { max: 200 }]}
                                    >
                                        <Input placeholder={t('workflow.definitions.stepNamePlaceholder', 'e.g. Manager Approval')} />
                                    </Form.Item>

                                    <Form.Item
                                        {...restField}
                                        name={[name, 'type']}
                                        label={t('workflow.definitions.stepType', 'Type')}
                                        tooltip={t('workflow.definitions.stepTypeHelp', 'sequential/any_of = any one approver, parallel = all must approve.')}
                                        rules={[{ required: true }]}
                                    >
                                        <Select options={STEP_TYPE_OPTIONS} />
                                    </Form.Item>

                                    <Form.Item label={t('workflow.definitions.approver', 'Approver')} style={{ marginBottom: 0 }}>
                                        <Flex vertical gap={8}>
                                            <ApproverFields
                                                restField={restField}
                                                name={name}
                                                roles={roles}
                                                users={users}
                                                form={form}
                                                t={t}
                                            />
                                        </Flex>
                                    </Form.Item>

                                    <Flex gap={12}>
                                        <Form.Item
                                            {...restField}
                                            name={[name, 'deadline_hours']}
                                            label={t('workflow.definitions.deadlineHours', 'Deadline (hours)')}
                                            tooltip={t('workflow.definitions.deadlineHoursHelp', 'Hours before escalation. Leave empty for no deadline.')}
                                            style={{ flex: 1 }}
                                        >
                                            <Input type="number" min={1} placeholder="24" />
                                        </Form.Item>

                                        <Form.Item
                                            {...restField}
                                            name={[name, 'escalate_to_role']}
                                            label={t('workflow.definitions.escalateToRole', 'Escalate To Role')}
                                            tooltip={t('workflow.definitions.escalateToRoleHelp', 'Role to escalate to when deadline expires. Leave empty for auto-approve.')}
                                            style={{ flex: 1 }}
                                        >
                                            <Select
                                                allowClear
                                                showSearch
                                                placeholder={t('workflow.definitions.selectRole', 'Select role...')}
                                                optionFilterProp="label"
                                                options={roles.map((r) => ({
                                                    value: r.slug,
                                                    label: r.name,
                                                }))}
                                            />
                                        </Form.Item>
                                    </Flex>
                                </Card>
                            ))}

                            <Button
                                type="dashed"
                                onClick={() => add({ ...DEFAULT_STEP })}
                                icon={<Plus size={14} />}
                                block
                            >
                                {t('workflow.definitions.addStep', 'Add Step')}
                            </Button>

                            <Form.ErrorList errors={errors} />
                        </Flex>
                    )}
                </Form.List>
            </Form>
        </Drawer>
    );
}

/* ── Main Page ─────────────────────────────────────── */

export default function WorkflowDefinitionsIndex({ definitions, filters, roles, users }: Props) {
    const { t } = useTranslation();
    const currentSort = parseSortParam(filters.sort);

    const [filterDrawerOpen, setFilterDrawerOpen] = useState(false);
    const [draftFilters, setDraftFilters] = useState<Record<string, string | undefined>>({});

    const [formDrawerOpen, setFormDrawerOpen] = useState(false);
    const [editingDef, setEditingDef] = useState<WorkflowDefinition | null>(null);

    const { message, modal } = App.useApp();

    const breadcrumbs = [
        { title: t('nav.dashboard', 'Dashboard'), href: '/dashboard' },
        { title: t('workflow.definitions.title', 'Workflow Definitions'), href: '/admin/workflow/definitions' },
    ];

    const deleteMutation = useMutation({
        mutationFn: (id: string) => workflowService.deleteDefinition(id),
        onSuccess: () => {
            message.success(t('workflow.definitions.deleted', 'Workflow definition deleted.'));
            router.reload();
        },
        onError: (error) => {
            if (isAxiosError(error) && error.response?.status === 422) {
                message.error(error.response.data.message);
            }
        },
    });

    const handleDelete = (def: WorkflowDefinition) => {
        modal.confirm({
            title: t('workflow.definitions.deleteConfirm', 'Delete this workflow definition?'),
            content: t('workflow.definitions.deleteConfirmContent', 'This action cannot be undone. Definitions with active instances cannot be deleted.'),
            okText: t('common.delete', 'Delete'),
            okButtonProps: { danger: true },
            onOk: () => deleteMutation.mutateAsync(def.id),
        });
    };

    const handleTableChange: TableProps<WorkflowDefinition>['onChange'] = (pagination, _filters, sorter) => {
        const s = Array.isArray(sorter) ? sorter.find((item) => item.order) : sorter;
        const field = (s?.columnKey ?? s?.field) as string | undefined;
        const order = s?.order;

        let sortParam: string | undefined;
        if (field && order) {
            sortParam = order === 'descend' ? `-${field}` : field;
        }

        const params = buildFilterParams(filters, { set: { sort: sortParam } });
        if (pagination.current && pagination.current > 1) params.page = pagination.current;

        router.get('/admin/workflow/definitions', params, { preserveState: true, preserveScroll: true });
    };

    const handleOpenFilterDrawer = () => {
        setDraftFilters({
            is_active: filters.filter?.is_active ?? undefined,
        });
        setFilterDrawerOpen(true);
    };

    const handleFilterApply = () => {
        router.get(
            '/admin/workflow/definitions',
            buildFilterParams(filters, { setAdvanced: draftFilters }),
            { preserveState: true, preserveScroll: true },
        );
        setFilterDrawerOpen(false);
    };

    const handleFilterReset = () => {
        router.get(
            '/admin/workflow/definitions',
            buildFilterParams(filters, { setAdvanced: {} }),
            { preserveState: true, preserveScroll: true },
        );
        setFilterDrawerOpen(false);
    };

    const handleCreate = () => {
        setEditingDef(null);
        setFormDrawerOpen(true);
    };

    const handleEdit = (def: WorkflowDefinition) => {
        setEditingDef(def);
        setFormDrawerOpen(true);
    };

    const columns: TableColumnsType<WorkflowDefinition> = [
        {
            title: t('workflow.definitions.name', 'Name'),
            dataIndex: 'name',
            key: 'name',
            sorter: true,
            sortOrder: currentSort.field === 'name' ? currentSort.order : null,
        },
        {
            title: t('workflow.definitions.slug', 'Slug'),
            dataIndex: 'slug',
            key: 'slug',
            sorter: true,
            sortOrder: currentSort.field === 'slug' ? currentSort.order : null,
        },
        {
            title: t('workflow.definitions.stepsCount', 'Steps'),
            dataIndex: 'steps_count',
            key: 'steps_count',
            width: 80,
            align: 'center',
        },
        {
            title: t('workflow.definitions.columns.status', 'Status'),
            key: 'is_active',
            sorter: true,
            sortOrder: currentSort.field === 'is_active' ? currentSort.order : null,
            render: (_, record) => (
                <Tag color={record.is_active ? 'blue' : undefined}>
                    {record.is_active
                        ? t('common.active', 'Active')
                        : t('common.inactive', 'Inactive')}
                </Tag>
            ),
        },
        {
            key: 'actions',
            width: 48,
            align: 'center',
            render: (_, record) => (
                <Dropdown
                    trigger={['click']}
                    placement="bottomRight"
                    menu={{
                        items: [
                            {
                                key: 'edit',
                                label: t('common.edit', 'Edit'),
                                onClick: () => handleEdit(record),
                            },
                            { type: 'divider' },
                            {
                                key: 'delete',
                                label: t('common.delete', 'Delete'),
                                danger: true,
                                onClick: () => handleDelete(record),
                            },
                        ],
                    }}
                >
                    <Button type="text" size="small" icon={<Ellipsis size={16} />} />
                </Dropdown>
            ),
        },
    ];

    const chipLabels: Record<string, string> = {
        is_active: t('workflow.definitions.columns.status', 'Status'),
    };

    const chipValueLabels: Record<string, Record<string, string>> = {
        is_active: {
            '1': t('common.active', 'Active'),
            '0': t('common.inactive', 'Inactive'),
        },
    };

    return (
        <PageContainer
            title={t('workflow.definitions.title', 'Workflow Definitions')}
            subtitle={t('workflow.definitions.subtitle', 'Manage approval workflow templates and their steps.')}
            breadcrumbs={breadcrumbs}
        >
            <Filters routeUrl="/admin/workflow/definitions" currentFilters={filters}>
                <FilterSearch
                    filterKey="q"
                    placeholder={t('workflow.definitions.searchPlaceholder', 'Search by name or slug...')}
                    style={{ maxWidth: 320 }}
                />
                <FilterAdvancedButton
                    label={t('common.filters', 'Filters')}
                    onClick={handleOpenFilterDrawer}
                />
                <div style={{ marginLeft: 'auto' }}>
                    <Button type="primary" icon={<PlusCircle size={16} />} onClick={handleCreate}>
                        {t('workflow.definitions.create', 'Create Definition')}
                    </Button>
                </div>
            </Filters>

            <FilterChips
                labels={chipLabels}
                valueLabels={chipValueLabels}
                currentFilters={filters}
                onRemove={(key) => {
                    router.get(
                        '/admin/workflow/definitions',
                        buildFilterParams(filters, { removeAdvancedKey: key }),
                        { preserveState: true, preserveScroll: true },
                    );
                }}
            />

            <Table
                dataSource={definitions.data}
                columns={columns}
                rowKey="id"
                onChange={handleTableChange}
                pagination={{
                    current: definitions.meta.current_page,
                    total: definitions.meta.total,
                    pageSize: definitions.meta.per_page,
                }}
            />

            <FilterDrawer
                open={filterDrawerOpen}
                onClose={() => setFilterDrawerOpen(false)}
                title={t('common.advancedFilters', 'Advanced Filters')}
                onApply={handleFilterApply}
                onReset={handleFilterReset}
            >
                <Flex vertical gap={4}>
                    <Typography.Text>{t('workflow.definitions.columns.status', 'Status')}</Typography.Text>
                    <Select
                        value={draftFilters.is_active ?? '__all__'}
                        onChange={(v) => setDraftFilters((prev) => ({ ...prev, is_active: v === '__all__' ? undefined : v }))}
                        options={[
                            { value: '__all__', label: t('common.all', 'All') },
                            { value: '1', label: t('common.active', 'Active') },
                            { value: '0', label: t('common.inactive', 'Inactive') },
                        ]}
                        style={{ width: '100%' }}
                    />
                </Flex>
            </FilterDrawer>

            <DefinitionFormDrawer
                open={formDrawerOpen}
                onClose={() => setFormDrawerOpen(false)}
                definition={editingDef}
                roles={roles}
                users={users}
            />
        </PageContainer>
    );
}

import { router } from '@inertiajs/react';
import { Button, DatePicker, Flex, Select, Table, Tag, Typography } from 'antd';
import type { TableColumnsType, TableProps } from 'antd';
import dayjs from 'dayjs';
import { Eye } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageContainer } from '@omnify-core/components/page-container';
import {
    buildFilterParams,
    FilterAdvancedButton,
    FilterChips,
    FilterDrawer,
    Filters,
    FilterSearch,
} from '@omnify-core/components/filters';
import type {
    PaginatedResponse,
    WorkflowDefinition,
    WorkflowFilters,
    WorkflowInstance,
    WorkflowStatusValue,
} from '@omnify-workflow/types/workflow';
import { workflowStatusColor } from '@omnify-workflow/types/workflow';

/* ── Types ─────────────────────────────────────────── */

type Props = {
    instances: PaginatedResponse<WorkflowInstance>;
    definitions: Pick<WorkflowDefinition, 'id' | 'name' | 'slug'>[];
    filters: WorkflowFilters;
};

/* ── Helpers ────────────────────────────────────────── */

function parseSortParam(sort: string | null | undefined): { field: string; order: 'ascend' | 'descend' } {
    if (typeof sort !== 'string' || sort === '') return { field: 'submitted_at', order: 'descend' };
    if (sort.startsWith('-')) return { field: sort.slice(1), order: 'descend' };
    return { field: sort, order: 'ascend' };
}

const STATUS_OPTIONS: { value: WorkflowStatusValue; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'cancelled', label: 'Cancelled' },
];

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

function shortId(id: string): string {
    return id.slice(0, 8);
}

/* ── Main Page ─────────────────────────────────────── */

export default function WorkflowInstancesIndex({ instances, definitions, filters }: Props) {
    const { t } = useTranslation();
    const currentSort = parseSortParam(filters.sort);

    const [filterDrawerOpen, setFilterDrawerOpen] = useState(false);
    const [draftFilters, setDraftFilters] = useState<Record<string, string | undefined>>({});

    const breadcrumbs = [
        { title: t('nav.dashboard', 'Dashboard'), href: '/dashboard' },
        { title: t('workflow.instances.title', 'Workflow Instances'), href: '/admin/workflow/instances' },
    ];

    const handleTableChange: TableProps<WorkflowInstance>['onChange'] = (pagination, _filters, sorter) => {
        const s = Array.isArray(sorter) ? sorter.find((item) => item.order) : sorter;
        const field = (s?.columnKey ?? s?.field) as string | undefined;
        const order = s?.order;

        let sortParam: string | undefined;
        if (field && order) {
            sortParam = order === 'descend' ? `-${field}` : field;
        }

        const params = buildFilterParams(filters, { set: { sort: sortParam } });
        if (pagination.current && pagination.current > 1) params.page = pagination.current;

        router.get('/admin/workflow/instances', params, { preserveState: true, preserveScroll: true });
    };

    const handleOpenFilterDrawer = () => {
        setDraftFilters({
            status: filters.filter?.status ?? undefined,
            definition_id: filters.filter?.definition_id ?? undefined,
            submitted_at_from: filters.filter?.submitted_at_from ?? undefined,
            submitted_at_to: filters.filter?.submitted_at_to ?? undefined,
        });
        setFilterDrawerOpen(true);
    };

    const handleFilterApply = () => {
        router.get(
            '/admin/workflow/instances',
            buildFilterParams(filters, { setAdvanced: draftFilters }),
            { preserveState: true, preserveScroll: true },
        );
        setFilterDrawerOpen(false);
    };

    const handleFilterReset = () => {
        router.get(
            '/admin/workflow/instances',
            buildFilterParams(filters, { setAdvanced: {} }),
            { preserveState: true, preserveScroll: true },
        );
        setFilterDrawerOpen(false);
    };

    const columns: TableColumnsType<WorkflowInstance> = [
        {
            title: t('workflow.instances.id', 'ID'),
            key: 'id',
            width: 100,
            render: (_, record) => (
                <Typography.Text code>{shortId(record.id)}</Typography.Text>
            ),
        },
        {
            title: t('workflow.instances.definition', 'Definition'),
            key: 'definition',
            render: (_, record) => record.definition?.name ?? '—',
        },
        {
            title: t('workflow.instances.type', 'Type'),
            dataIndex: 'workflowable_type',
            key: 'workflowable_type',
        },
        {
            title: t('workflow.instances.status', 'Status'),
            key: 'status',
            sorter: true,
            sortOrder: currentSort.field === 'status' ? currentSort.order : null,
            render: (_, record) => (
                <Tag color={workflowStatusColor[record.status]}>
                    {statusLabel(record.status)}
                </Tag>
            ),
        },
        {
            title: t('workflow.instances.currentStep', 'Step'),
            dataIndex: 'current_step',
            key: 'current_step',
            width: 70,
            align: 'center',
        },
        {
            title: t('workflow.instances.submittedAt', 'Submitted'),
            key: 'submitted_at',
            sorter: true,
            sortOrder: currentSort.field === 'submitted_at' ? currentSort.order : null,
            render: (_, record) => record.submitted_at
                ? dayjs(record.submitted_at).format('YYYY-MM-DD HH:mm')
                : '—',
        },
        {
            key: 'actions',
            width: 48,
            align: 'center',
            render: (_, record) => (
                <Button
                    type="text"
                    size="small"
                    icon={<Eye size={16} />}
                    onClick={() => router.visit(`/admin/workflow/instances/${record.id}`)}
                />
            ),
        },
    ];

    const definitionOptions = definitions.map((d) => ({
        value: d.id,
        label: d.name,
    }));

    const chipLabels: Record<string, string> = {
        status: t('workflow.instances.status', 'Status'),
        definition_id: t('workflow.instances.definition', 'Definition'),
        submitted_at_from: t('common.from', 'From'),
        submitted_at_to: t('common.to', 'To'),
    };

    const chipValueLabels: Record<string, Record<string, string>> = {
        status: Object.fromEntries(STATUS_OPTIONS.map((o) => [o.value, o.label])),
        definition_id: Object.fromEntries(definitions.map((d) => [d.id, d.name])),
    };

    return (
        <PageContainer
            title={t('workflow.instances.title', 'Workflow Instances')}
            subtitle={t('workflow.instances.subtitle', 'Monitor and manage running workflow instances.')}
            breadcrumbs={breadcrumbs}
        >
            <Filters routeUrl="/admin/workflow/instances" currentFilters={filters}>
                <FilterSearch
                    filterKey="q"
                    placeholder={t('workflow.instances.searchPlaceholder', 'Search by type...')}
                    style={{ maxWidth: 320 }}
                />
                <FilterAdvancedButton
                    label={t('common.filters', 'Filters')}
                    onClick={handleOpenFilterDrawer}
                />
            </Filters>

            <FilterChips
                labels={chipLabels}
                valueLabels={chipValueLabels}
                currentFilters={filters}
                onRemove={(key) => {
                    router.get(
                        '/admin/workflow/instances',
                        buildFilterParams(filters, { removeAdvancedKey: key }),
                        { preserveState: true, preserveScroll: true },
                    );
                }}
            />

            <Table
                dataSource={instances.data}
                columns={columns}
                rowKey="id"
                onChange={handleTableChange}
                pagination={{
                    current: instances.meta.current_page,
                    total: instances.meta.total,
                    pageSize: instances.meta.per_page,
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
                    <Typography.Text>{t('workflow.instances.status', 'Status')}</Typography.Text>
                    <Select
                        value={draftFilters.status ?? '__all__'}
                        onChange={(v) => setDraftFilters((prev) => ({ ...prev, status: v === '__all__' ? undefined : v }))}
                        options={[
                            { value: '__all__', label: t('common.all', 'All') },
                            ...STATUS_OPTIONS,
                        ]}
                        style={{ width: '100%' }}
                    />
                </Flex>
                <Flex vertical gap={4}>
                    <Typography.Text>{t('workflow.instances.definition', 'Definition')}</Typography.Text>
                    <Select
                        value={draftFilters.definition_id ?? '__all__'}
                        onChange={(v) => setDraftFilters((prev) => ({ ...prev, definition_id: v === '__all__' ? undefined : v }))}
                        options={[
                            { value: '__all__', label: t('common.all', 'All') },
                            ...definitionOptions,
                        ]}
                        style={{ width: '100%' }}
                    />
                </Flex>
                <Flex vertical gap={4}>
                    <Typography.Text>{t('common.submittedFrom', 'Submitted From')}</Typography.Text>
                    <DatePicker
                        value={draftFilters.submitted_at_from ? dayjs(draftFilters.submitted_at_from) : null}
                        onChange={(_, dateStr) => setDraftFilters((prev) => ({ ...prev, submitted_at_from: (dateStr as string) || undefined }))}
                        style={{ width: '100%' }}
                    />
                </Flex>
                <Flex vertical gap={4}>
                    <Typography.Text>{t('common.submittedTo', 'Submitted To')}</Typography.Text>
                    <DatePicker
                        value={draftFilters.submitted_at_to ? dayjs(draftFilters.submitted_at_to) : null}
                        onChange={(_, dateStr) => setDraftFilters((prev) => ({ ...prev, submitted_at_to: (dateStr as string) || undefined }))}
                        style={{ width: '100%' }}
                    />
                </Flex>
            </FilterDrawer>
        </PageContainer>
    );
}

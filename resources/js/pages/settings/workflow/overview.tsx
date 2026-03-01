import { Button, Card, Col, Empty, Flex, Row, Statistic, Tag, Typography, theme } from 'antd';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle, ClipboardList, FileText, PlayCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageContainer } from '@omnify-core/components/page-container';

type Props = {
    stats: {
        total_definitions: number;
        active_definitions: number;
        total_instances: number;
        pending_instances: number;
    };
    recent_instances: {
        id: string;
        status: string;
        submitted_at: string | null;
        definition_name: string | null;
    }[];
};

const STATUS_COLORS: Record<string, string> = {
    pending: 'processing',
    approved: 'success',
    rejected: 'error',
    cancelled: 'default',
    escalated: 'warning',
};

export default function WorkflowOverview({ stats, recent_instances }: Props) {
    const { t } = useTranslation();
    const { token } = theme.useToken();
    const { url } = usePage();
    const workflowBase = url.match(/^(.*\/settings\/workflow)/)?.[1] ?? '/settings/workflow';

    const statCards = [
        {
            label: t('workflow.stats.totalDefinitions', 'Total Definitions'),
            value: stats.total_definitions,
            icon: FileText,
        },
        {
            label: t('workflow.stats.activeDefinitions', 'Active Definitions'),
            value: stats.active_definitions,
            icon: CheckCircle,
        },
        {
            label: t('workflow.stats.totalInstances', 'Total Instances'),
            value: stats.total_instances,
            icon: ClipboardList,
        },
        {
            label: t('workflow.stats.pendingInstances', 'Pending Instances'),
            value: stats.pending_instances,
            icon: PlayCircle,
        },
    ];

    const quickLinks = [
        { href: `${workflowBase}/definitions`, label: t('workflow.definitions.title', 'Workflow Definitions'), icon: FileText },
        { href: `${workflowBase}/instances`, label: t('workflow.instances.title', 'Workflow Instances'), icon: ClipboardList },
    ];

    return (
        <PageContainer
            title={t('workflow.title', 'Workflow')}
            subtitle={t('workflow.subtitle', 'Manage approval workflows and definitions.')}
        >
            <Flex vertical gap={token.padding}>
                <Row gutter={[16, 16]}>
                    {statCards.map((stat) => {
                        const Icon = stat.icon;
                        return (
                            <Col key={stat.label} xs={24} sm={12} lg={6}>
                                <Card>
                                    <Statistic
                                        title={stat.label}
                                        value={stat.value}
                                        prefix={<Icon size={16} />}
                                    />
                                </Card>
                            </Col>
                        );
                    })}
                </Row>

                <Row gutter={[16, 16]}>
                    <Col xs={24} lg={12}>
                        <Card title={t('workflow.quickLinks', 'Quick Links')}>
                            <Flex vertical gap={token.paddingXXS}>
                                {quickLinks.map((link) => {
                                    const Icon = link.icon;
                                    return (
                                        <Link key={link.href} href={link.href}>
                                            <Button type="text" block>
                                                <Flex align="center" gap="small" flex={1} justify="space-between">
                                                    <Flex align="center" gap="small">
                                                        <Icon size={16} />
                                                        <Typography.Text>{link.label}</Typography.Text>
                                                    </Flex>
                                                    <ArrowRight size={14} />
                                                </Flex>
                                            </Button>
                                        </Link>
                                    );
                                })}
                            </Flex>
                        </Card>
                    </Col>

                    <Col xs={24} lg={12}>
                        <Card title={t('workflow.recentInstances', 'Recent Instances')}>
                            {recent_instances.length === 0 ? (
                                <Empty description={t('workflow.noInstances', 'No workflow instances yet.')} />
                            ) : (
                                <Flex vertical gap={token.paddingXXS}>
                                    {recent_instances.map((instance) => (
                                        <Link
                                            key={instance.id}
                                            href={`${workflowBase}/instances/${instance.id}`}
                                        >
                                            <Button type="text" block>
                                                <Flex align="center" gap="small" flex={1} justify="space-between">
                                                    <Flex vertical align="start" style={{ minWidth: 0 }}>
                                                        <Typography.Text strong ellipsis>
                                                            {instance.definition_name ?? instance.id.slice(0, 8)}
                                                        </Typography.Text>
                                                        <Typography.Text type="secondary" ellipsis>
                                                            {instance.submitted_at
                                                                ? new Date(instance.submitted_at).toLocaleDateString()
                                                                : '—'}
                                                        </Typography.Text>
                                                    </Flex>
                                                    <Tag color={STATUS_COLORS[instance.status] ?? 'default'}>
                                                        {instance.status}
                                                    </Tag>
                                                </Flex>
                                            </Button>
                                        </Link>
                                    ))}
                                </Flex>
                            )}
                        </Card>
                    </Col>
                </Row>
            </Flex>
        </PageContainer>
    );
}

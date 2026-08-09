// S-UX3-1 — the withdrawal-decision queue (operations.manage). Approving a withdrawal is
// BI-7 terminal: the confirm copy STATES that. Reject is reasoned. Server errors surfaced;
// queue refreshes after a decide. The server owns the withdrawal workflow — this UI drives it.
import { useState } from 'react';
import { App, Button, Space, Table, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { ReasonModal } from '../components/ReasonModal';
import { StatusTag } from '../display/status';
import { formatHkt } from '../display/date';
// DS2 (restyle rollout M3 — money tier). ALLOWED adopter (import-guard). Appearance only:
// Card→SubPanel framing; the table, BI-7 decision buttons and status pill are byte-identical.
import { SubPanel } from '@/ds2';

const { Title, Paragraph } = Typography;

interface Row {
  id: string;
  student_name: string | null;
  requested_by_name: string | null;
  reason: string;
  status: string;
  decided_by_name: string | null;
  decided_at: string | null;
}

export function Withdrawals() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const { data, loading, error, reload } = useResource<{ data: Row[] }>('/api/withdrawal-requests');
  const [rejectId, setRejectId] = useState<string | null>(null);
  const rows = data?.data ?? [];

  const surface = (r: MutateResult) => {
    if (r.ok) {
      void message.success(t('mutate.success'));
      reload();
      return;
    }
    void message.error(
      r.message ??
        (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')),
    );
  };

  const approve = (row: Row) =>
    modal.confirm({
      title: t('withdrawals.approveTitle', { student: row.student_name ?? '—' }),
      content: t('withdrawals.approveBody'), // "This is terminal — the enrolment ends."
      okText: t('withdrawals.approve'),
      cancelText: t('common.cancel'),
      okButtonProps: { danger: true },
      onOk: async () => surface(await mutate(`/api/admin/withdrawal-requests/${row.id}/decide`, { approve: true })),
    });

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('withdrawals.title')}</Title>
        <Paragraph type="secondary">{t('withdrawals.subtitle')}</Paragraph>
      </div>

      <DataBoundary loading={loading} error={error}>
        <SubPanel tone="neutral">
          <Table<Row>
            rowKey="id"
            size="small"
            dataSource={rows}
            pagination={false}
            columns={[
              { title: t('withdrawals.student'), dataIndex: 'student_name', render: (v: string | null) => v ?? '—' },
              { title: t('withdrawals.requestedBy'), dataIndex: 'requested_by_name', render: (v: string | null) => v ?? '—' },
              { title: t('withdrawals.reason'), dataIndex: 'reason' },
              { title: '', dataIndex: 'status', render: (s: string) => <StatusTag domain="withdrawalStatus" value={s} /> },
              {
                title: t('withdrawals.decided'), key: 'decided',
                render: (_, r) => (r.decided_by_name ? `${r.decided_by_name} · ${formatHkt(r.decided_at, locale)}` : '—'),
              },
              {
                title: '', key: 'act',
                render: (_, r) =>
                  r.status === 'pending' ? (
                    <Space>
                      <Button size="small" type="primary" danger onClick={() => approve(r)}>{t('withdrawals.approve')}</Button>
                      <Button size="small" onClick={() => setRejectId(r.id)}>{t('withdrawals.reject')}</Button>
                    </Space>
                  ) : null,
              },
            ]}
          />
        </SubPanel>
      </DataBoundary>

      <ReasonModal
        open={rejectId !== null}
        title={t('withdrawals.rejectTitle')}
        okText={t('withdrawals.reject')}
        danger={false}
        onOk={async (reason) => {
          const id = rejectId;
          setRejectId(null);
          if (id) surface(await mutate(`/api/admin/withdrawal-requests/${id}/decide`, { approve: false, reason }));
        }}
        onCancel={() => setRejectId(null)}
      />
    </Space>
  );
}

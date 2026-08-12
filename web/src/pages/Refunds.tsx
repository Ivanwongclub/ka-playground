// S-UX3-2 — refund payouts: approve → confirm (BI-9: approver ≠ confirmer, refused server-side AND at
// the DB rf_update WITH CHECK) or reject. Confirm shown to every finance.confirm holder; the same-person
// refusal (403) is surfaced, never pre-hidden. Amounts via formatMoney. Refresh after mutate.
import { useState } from 'react';
import { App, Button, Descriptions, Space, Table, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { ReasonModal } from '../components/ReasonModal';
import { formatMoney } from '../display/money';
import { StatusTag } from '../display/status';
import { personName } from '../display/names';
// DS2 (restyle rollout M1 — money tier). ALLOWED adopter (import-guard). Appearance only:
// Card→SubPanel framing; the table, BI-9 buttons, amounts and status pills are byte-identical.
import { SubPanel } from '@/ds2';

const { Title, Paragraph, Text } = Typography;

interface RefundRow {
  id: string;
  amount_minor: number;
  currency: string;
  destination_party: string;
  status: string;
  approved_by: number | null;
  confirmed_by: number | null;
  approved_by_name: string | null;
  confirmed_by_name: string | null;
  student_name: string | null;
  // R1-F1 (item 4): the ORIGIN — the originating withdrawal's display fields (resolved via the governed
  // elevation server-side, since finance is not in wr_read).
  withdrawal_reason: string | null;
  withdrawal_requested_by_name: string | null;
  withdrawal_decided_by_name: string | null;
}

type Reasoned = { url: string; title: string; okText: string; minLen: number; field: 'reason' | 'evidence_note' } | null;

export function Refunds() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const { data, loading, error, reload } = useResource<{ data: RefundRow[] }>('/api/refunds');
  const [reasoned, setReasoned] = useState<Reasoned>(null);
  const rows = data?.data ?? [];

  const surface = (r: MutateResult) => {
    if (r.ok) {
      void message.success(t('mutate.success'));
      reload();
      return;
    }
    void message.error(
      r.message ?? (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')),
    );
  };

  // BI-9: shown to every finance.confirm holder; a same-person (approver) confirm is refused (403), surfaced.
  const confirmRefund = (row: RefundRow) =>
    modal.confirm({
      title: t('refund.confirmTitle'),
      content: t('refund.confirmBody'),
      okText: t('refund.confirm'),
      cancelText: t('common.cancel'),
      okButtonProps: { danger: true },
      onOk: async () => surface(await mutate(`/api/admin/refunds/${row.id}/confirm`)),
    });

  // R1-F1 (item 4): the originating withdrawal in the same view as the refund decision.
  const renderDetail = (r: RefundRow) => (
    <Descriptions size="small" column={1} bordered>
      <Descriptions.Item label={t('refund.dReason')}>{r.withdrawal_reason ?? '—'}</Descriptions.Item>
      <Descriptions.Item label={t('refund.dRequestedBy')}>{personName(r.withdrawal_requested_by_name)}</Descriptions.Item>
      <Descriptions.Item label={t('refund.dDecidedBy')}>{personName(r.withdrawal_decided_by_name)}</Descriptions.Item>
    </Descriptions>
  );

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('refund.title')}</Title>
        <Paragraph type="secondary">{t('refund.subtitle')}</Paragraph>
      </div>

      <DataBoundary loading={loading} error={error} empty={rows.length === 0}>
        <SubPanel tone="neutral">
          <Table<RefundRow>
            rowKey="id"
            size="small"
            dataSource={rows}
            pagination={false}
            expandable={{ expandedRowRender: renderDetail }}
            columns={[
              { title: t('refund.student'), render: (_, r) => personName(r.student_name) },
              { title: t('refund.amount'), render: (_, r) => <Text strong>{formatMoney(r.amount_minor, r.currency, locale)}</Text> },
              { title: t('refund.destination'), dataIndex: 'destination_party', render: (v: string) => <StatusTag domain="refundDestination" value={v} /> },
              { title: t('common.status'), dataIndex: 'status', render: (s: string) => <StatusTag domain="refundStatus" value={s} /> },
              // BI-9: the confirmer SEES who approved it.
              { title: t('refund.approvedBy'), render: (_, r) => personName(r.approved_by_name) },
              {
                title: t('common.actions'), key: 'act',
                render: (_, r) =>
                  r.status === 'requested' ? (
                    <Space>
                      <Button size="small" type="primary" onClick={() => setReasoned({ url: `/api/admin/refunds/${r.id}/approve`, title: t('refund.approveTitle'), okText: t('refund.approve'), minLen: 3, field: 'evidence_note' })}>{t('refund.approve')}</Button>
                      <Button size="small" danger onClick={() => setReasoned({ url: `/api/admin/refunds/${r.id}/reject`, title: t('refund.rejectTitle'), okText: t('refund.reject'), minLen: 5, field: 'reason' })}>{t('refund.reject')}</Button>
                    </Space>
                  ) : r.status === 'approved' ? (
                    <Space>
                      <Button size="small" type="primary" danger onClick={() => confirmRefund(r)}>{t('refund.confirm')}</Button>
                      <Button size="small" danger onClick={() => setReasoned({ url: `/api/admin/refunds/${r.id}/reject`, title: t('refund.rejectTitle'), okText: t('refund.reject'), minLen: 5, field: 'reason' })}>{t('refund.reject')}</Button>
                    </Space>
                  ) : null,
              },
            ]}
          />
        </SubPanel>
      </DataBoundary>

      <ReasonModal
        open={reasoned !== null}
        title={reasoned?.title ?? ''}
        okText={reasoned?.okText ?? ''}
        minLen={reasoned?.minLen ?? 3}
        onOk={async (text) => {
          const target = reasoned;
          setReasoned(null);
          if (target) surface(await mutate(target.url, { [target.field]: text }));
        }}
        onCancel={() => setReasoned(null)}
      />
    </Space>
  );
}

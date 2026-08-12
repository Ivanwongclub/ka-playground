// S-UX3-1 — the withdrawal-decision queue (operations.manage). Approving a withdrawal is
// BI-7 terminal: the confirm copy STATES that. Reject is reasoned. Server errors surfaced;
// queue refreshes after a decide. The server owns the withdrawal workflow — this UI drives it.
import { useState } from 'react';
import { App, Button, Descriptions, Space, Table, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { ReasonModal } from '../components/ReasonModal';
import { StatusTag } from '../display/status';
import { programmeName } from '../display/names';
import { formatHkt } from '../display/date';
// DS2 (restyle rollout M3 — money tier). ALLOWED adopter (import-guard). Appearance only:
// Card→SubPanel framing; the table, BI-7 decision buttons and status pill are byte-identical.
import { SubPanel } from '@/ds2';

const { Title, Paragraph } = Typography;

interface Endorsement { endorser_name: string | null; endorser_role: string; comment: string; created_at: string }
interface Row {
  id: string;
  student_name: string | null;
  requested_by_name: string | null;
  reason: string;
  status: string;
  decided_by_name: string | null;
  decided_at: string | null;
  // R1-F1 (item 2): decision context — display fields only.
  programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null;
  decision_reason: string | null;
  full_refund_before: string | null;
  no_refund_after: string | null;
  endorsements: Endorsement[];
}

// Normalise a pg timestamptz for honest comparison (mirrors display/date + urgency).
const parseTs = (s: string): number => Date.parse(s.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));

// R1-F1 (item 2): the refund-WINDOW POSITION vs now — display computation only (the server owns the money).
function refundWindow(row: Row): { level: 'full' | 'partial' | 'none' | 'unknown'; until: string | null } {
  const { full_refund_before: fb, no_refund_after: na } = row;
  if (!fb && !na) return { level: 'unknown', until: null };
  const now = Date.now();
  if (fb && now < parseTs(fb)) return { level: 'full', until: fb };
  if (na && now >= parseTs(na)) return { level: 'none', until: null };
  return { level: 'partial', until: null };
}
// Reuse the DS2 urgency TINT tokens — no invented colours (gold = full, warning = partial, danger = none).
const REFUND_TINT: Record<'full' | 'partial' | 'none', [string, string]> = {
  full: ['--ka-gold-tint', '--ka-gold-line'],
  partial: ['--ka-warning-tint', '--ka-warning-line'],
  none: ['--ka-danger-tint', '--ka-danger-line'],
};

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

  // R1-F1 (item 2): the decision's context, in the same view as the decide control.
  const refundChip = (row: Row) => {
    const w = refundWindow(row);
    if (w.level === 'unknown') return <>—</>;
    const [bg, bd] = REFUND_TINT[w.level];
    const label = w.level === 'full' ? t('withdrawals.refundFull', { date: formatHkt(w.until as string, locale) })
      : w.level === 'partial' ? t('withdrawals.refundPartial') : t('withdrawals.refundNone');
    return <span style={{ background: `var(${bg})`, border: `1px solid var(${bd})`, borderRadius: 6, padding: '2px 9px', fontSize: 12 }}>{label}</span>;
  };
  const renderDetail = (row: Row) => (
    <Descriptions size="small" column={1} bordered>
      <Descriptions.Item label={t('withdrawals.dProgramme')}>{programmeName(row, locale)}</Descriptions.Item>
      <Descriptions.Item label={t('withdrawals.dRefundWindow')}>{refundChip(row)}</Descriptions.Item>
      {row.decision_reason ? <Descriptions.Item label={t('withdrawals.dDecisionReason')}>{row.decision_reason}</Descriptions.Item> : null}
      <Descriptions.Item label={t('withdrawals.dEndorsements')}>
        {row.endorsements.length === 0 ? t('withdrawals.noEndorsements') : (
          <Space direction="vertical" size={4} style={{ width: '100%' }}>
            {/* endorser NAME degrades to an em-dash when users_read hides it — the endorsement never drops. */}
            {row.endorsements.map((e, i) => (
              <div key={i} style={{ fontSize: 13 }}><strong>{e.endorser_name ?? '—'}</strong> · {e.endorser_role} — {e.comment}</div>
            ))}
          </Space>
        )}
      </Descriptions.Item>
    </Descriptions>
  );

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
            expandable={{ expandedRowRender: renderDetail }}
            columns={[
              { title: t('withdrawals.student'), dataIndex: 'student_name', render: (v: string | null) => v ?? '—' },
              { title: t('withdrawals.requestedBy'), dataIndex: 'requested_by_name', render: (v: string | null) => v ?? '—' },
              { title: t('withdrawals.reason'), dataIndex: 'reason' },
              { title: t('common.status'), dataIndex: 'status', render: (s: string) => <StatusTag domain="withdrawalStatus" value={s} /> },
              {
                title: t('withdrawals.decided'), key: 'decided',
                render: (_, r) => (r.decided_by_name ? `${r.decided_by_name} · ${formatHkt(r.decided_at, locale)}` : '—'),
              },
              {
                title: t('common.actions'), key: 'act',
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

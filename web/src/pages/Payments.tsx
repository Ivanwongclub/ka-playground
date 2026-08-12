// S-UX3-2 / anchors STEP 3 — money mutations: record a manual payment (evidence upload, full amount, OD-5),
// then a SECOND finance person confirms/rejects it (BI-9). BI-9 is SERVER-enforced: the server refuses a
// same-person confirm OR reject (403), and this UI ALSO renders the recorder's own Confirm/Reject as
// DISABLED with the reason (shown-not-hidden — the controls are shown, not hidden). Amounts via formatMoney.
// DS2 restyle: markup only — the mutate calls, endpoints and server authority are UNTOUCHED.
import { useState } from 'react';
import { App, Button, Card, Descriptions, Input, Modal, Space, Typography, Upload } from 'antd';
import type { UploadFile } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { authFetch } from '../auth/session';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { ReasonModal } from '../components/ReasonModal';
import { formatMoney } from '../display/money';
import { StatusTag } from '../display/status';
import { programmeName, personName } from '../display/names';
import { useIdentity } from '../auth/identity';
import { ZebraTable, StatCard } from '@/ds2';

const { Title, Paragraph, Text } = Typography;

// R0-B2: the local StatCard was consolidated into the DS2 StatCard (count/unit props, 30px value).

interface OrderRow {
  id: string;
  student_id: number;
  status: string;
  total_amount_minor: number;
  currency: string;
  student_name: string | null;
  programme_name_en: string | null;
  programme_name_tc: string | null;
  programme_name_sc: string | null;
}
interface PaymentRow {
  id: string;
  order_id: string;
  amount_minor: number;
  currency: string;
  status: string;
  recorded_by: number;
  confirmed_by: number | null;
  recorded_by_name: string | null;
  confirmed_by_name: string | null;
  student_name: string | null;
  note: string | null; // R1-F1 (item 3a): the recorder's note
}
interface EvidenceMeta { upload_id: string; original_name: string; mime_type: string; size_bytes: number; status: string; scanned_at: string | null }

// R1-F1 (item 3b) — the confirming officer's PROOF: the recorder's note + the scanned evidence file(s), in
// the same view as the confirm control. Evidence metadata is a LAZY read (fires only on row expand). Only
// clean files download (fetched WITH the bearer token, then streamed to a blob); pending/quarantined render
// honestly. This is the four-eyes control's missing half — display only, no decision-chain change.
function PaymentEvidence({ payment }: { payment: PaymentRow }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const ev = useResource<{ data: EvidenceMeta[] }>(`/api/payments/${payment.id}/evidence`);
  const download = async (m: EvidenceMeta) => {
    // authFetch carries the bearer token — a plain <a href> could not, and the endpoint is finance-gated.
    const r = await authFetch(`/api/payments/${payment.id}/evidence/${m.upload_id}/download`);
    if (!r.ok) { void message.error(t('payments.evUnavailable')); return; }
    const blob = await r.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = m.original_name || 'evidence';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };
  return (
    <Descriptions size="small" column={1} bordered>
      <Descriptions.Item label={t('payments.dNote')}>{payment.note || '—'}</Descriptions.Item>
      <Descriptions.Item label={t('payments.dEvidence')}>
        <DataBoundary loading={ev.loading} error={ev.error} empty={(ev.data?.data.length ?? 0) === 0} emptySize="inline">
          <Space direction="vertical" size={4} style={{ width: '100%' }}>
            {(ev.data?.data ?? []).map((m) => (
              <span key={m.upload_id} style={{ display: 'inline-flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <Typography.Text>{m.original_name}</Typography.Text>
                {m.status === 'clean'
                  ? <Button size="small" onClick={() => void download(m)}>{t('payments.evDownload')}</Button>
                  : m.status === 'pending'
                    ? <Typography.Text type="secondary">{t('payments.evScanning')}</Typography.Text>
                    : <Typography.Text type="danger">{t('payments.evQuarantined')}</Typography.Text>}
              </span>
            ))}
          </Space>
        </DataBoundary>
      </Descriptions.Item>
    </Descriptions>
  );
}

export function Payments() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const { identity } = useIdentity();
  const meId = identity?.id; // BI-9 cue: the current finance officer's id (the server is the authority)
  const orders = useResource<{ data: OrderRow[] }>('/api/orders');
  const payments = useResource<{ data: PaymentRow[] }>('/api/payments');
  const [recordFor, setRecordFor] = useState<OrderRow | null>(null);
  const [files, setFiles] = useState<UploadFile[]>([]);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [rejectId, setRejectId] = useState<string | null>(null);

  const reload = () => {
    orders.reload();
    payments.reload();
  };
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

  const allPayments = payments.data?.data ?? [];
  // Awaiting = an issued order with no recorded payment yet (a pending/confirmed payment moves it off the list).
  const settledOrders = new Set(allPayments.filter((p) => p.status !== 'rejected').map((p) => p.order_id));
  const awaiting = (orders.data?.data ?? []).filter((o) => o.status === 'issued' && !settledOrders.has(o.id));
  const pending = allPayments.filter((p) => p.status === 'pending_confirmation');
  const confirmed = allPayments.filter((p) => p.status === 'confirmed');
  // Summary stat-cards — ALL frontend-computed from the reads already fetched (/orders + /payments); NO new
  // aggregate. ("Confirmed" is all-time — the read has no reliable confirm-date for a "today" filter.)
  const currency = awaiting[0]?.currency ?? pending[0]?.currency ?? confirmed[0]?.currency ?? 'HKD';
  const sum = (arr: Array<{ amount_minor?: number; total_amount_minor?: number }>, key: 'amount_minor' | 'total_amount_minor') =>
    arr.reduce((acc, x) => acc + (x[key] ?? 0), 0);
  const outstandingMinor = sum(awaiting, 'total_amount_minor');
  const awaitingMinor = sum(pending, 'amount_minor');
  const confirmedMinor = sum(confirmed, 'amount_minor');

  const submitRecord = async () => {
    if (!recordFor || files.length === 0) return;
    setBusy(true);
    const fd = new FormData();
    fd.append('order_id', recordFor.id);
    fd.append('amount_minor', String(recordFor.total_amount_minor)); // OD-5: the FULL outstanding, never a partial
    fd.append('currency', recordFor.currency);
    if (note.trim()) fd.append('note', note.trim());
    files.forEach((f) => f.originFileObj && fd.append('evidence[]', f.originFileObj));
    const r = await mutate('/api/admin/payments', fd);
    setBusy(false);
    setRecordFor(null);
    setFiles([]);
    setNote('');
    surface(r);
  };

  // BI-9: shown to every finance.confirm holder; the server refuses a same-person confirm (403), surfaced.
  const confirmPayment = (p: PaymentRow) =>
    modal.confirm({
      title: t('payments.confirmTitle'),
      content: t('payments.confirmBody'),
      okText: t('payments.confirm'),
      cancelText: t('common.cancel'),
      onOk: async () => surface(await mutate(`/api/admin/payments/${p.id}/confirm`)),
    });

  return (
    <div data-density="admin">
      <Space direction="vertical" size="large" style={{ width: '100%' }}>
        <Title level={3} style={{ marginBottom: 0 }}>{t('payments.title')}</Title>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 'var(--ka-gap)' }}>
          <StatCard label={t('payments.outstanding')} value={formatMoney(outstandingMinor, currency, locale)} count={awaiting.length} unit={t('payments.unitOrders')} />
          <StatCard label={t('payments.awaiting')} value={formatMoney(awaitingMinor, currency, locale)} count={pending.length} unit={t('payments.unitToConfirm')} accent="warn" />
          <StatCard label={t('payments.confirmedLabel')} value={formatMoney(confirmedMinor, currency, locale)} count={confirmed.length} unit={t('payments.unitConfirmed')} accent="gold" seal />
        </div>

        {/* AL-4: these are SECTIONS within the page → inline empty (not a route-body page empty). */}
        <DataBoundary loading={orders.loading} error={orders.error} empty={awaiting.length === 0} emptySize="inline">
          <Card title={t('payments.ordersAwaiting')}>
            <ZebraTable<OrderRow>
              rowKey={(o) => o.id}
              data={awaiting}
              columns={[
                { key: 'student', title: t('payments.student'), type: 'text', render: (o) => personName(o.student_name) },
                { key: 'programme', title: t('payments.programme'), type: 'text', render: (o) => programmeName(o, locale) },
                { key: 'amount', title: t('payments.amount'), type: 'money', render: (o) => formatMoney(o.total_amount_minor, o.currency, locale) },
                { key: 'act', title: t('common.actions'), type: 'action', render: (o) => <Button size="small" type="primary" onClick={() => setRecordFor(o)}>{t('payments.record')}</Button> },
              ]}
            />
          </Card>
        </DataBoundary>

        <DataBoundary loading={payments.loading} error={payments.error} empty={pending.length === 0} emptySize="inline">
          <Card title={t('payments.pendingConfirmation')}>
            <ZebraTable<PaymentRow>
              rowKey={(p) => p.id}
              data={pending}
              renderDetail={(p) => <PaymentEvidence payment={p} />}
              columns={[
                { key: 'student', title: t('payments.student'), type: 'text', render: (p) => personName(p.student_name) },
                { key: 'amount', title: t('payments.amount'), type: 'money', render: (p) => formatMoney(p.amount_minor, p.currency, locale) },
                // BI-9: the confirmer SEES who recorded it; when it is themselves the row shows "You".
                { key: 'recordedBy', title: t('payments.recordedBy'), type: 'text',
                  render: (p) => p.recorded_by === meId
                    ? <span style={{ color: 'var(--ka-warning)', background: 'rgba(251,191,36,.12)', fontSize: 12, fontWeight: 600, padding: '2px 9px', borderRadius: 6 }}>{t('payments.you')}</span>
                    : personName(p.recorded_by_name) },
                { key: 'status', title: t('common.status'), type: 'status', render: (p) => <StatusTag domain="paymentStatus" value={p.status} /> },
                // BI-9 shown-not-hidden: the recorder's own Confirm/Reject are SHOWN but DISABLED with the
                // reason. The SERVER remains the authority (403 on a same-person confirm OR reject).
                { key: 'act', title: t('common.actions'), type: 'action', render: (p) => {
                    const mine = p.recorded_by === meId;
                    return (
                      <Space>
                        <Button size="small" type="primary" disabled={mine} title={mine ? t('payments.biNineYou') : undefined} onClick={() => confirmPayment(p)}>{t('payments.confirm')}</Button>
                        <Button size="small" danger disabled={mine} title={mine ? t('payments.biNineYou') : undefined} onClick={() => setRejectId(p.id)}>{t('payments.reject')}</Button>
                      </Space>
                    );
                  } },
              ]}
            />
          </Card>
        </DataBoundary>

      {/* Record-payment modal — full amount (read-only, OD-5), evidence upload (BI-10), optional note. */}
      <Modal
        open={recordFor !== null}
        title={t('payments.recordTitle')}
        okText={t('payments.record')}
        okButtonProps={{ loading: busy, disabled: files.length === 0 }}
        onOk={() => void submitRecord()}
        onCancel={() => { setRecordFor(null); setFiles([]); setNote(''); }}
      >
        {recordFor && (
          <Space direction="vertical" style={{ width: '100%' }}>
            <Paragraph>
              {t('payments.recordBody', {
                amount: formatMoney(recordFor.total_amount_minor, recordFor.currency, locale),
                student: personName(recordFor.student_name),
                programme: programmeName(recordFor, locale),
              })}
            </Paragraph>
            <Upload
              multiple
              beforeUpload={() => false}
              fileList={files}
              onChange={({ fileList }) => setFiles(fileList)}
              accept="image/*,application/pdf"
            >
              <Button>{t('payments.evidence')}</Button>
            </Upload>
            {files.length === 0 && <Text type="danger">{t('payments.evidenceRequired')}</Text>}
            <Input.TextArea rows={2} placeholder={t('payments.note')} value={note} onChange={(e) => setNote(e.target.value)} />
          </Space>
        )}
      </Modal>

      <ReasonModal
        open={rejectId !== null}
        title={t('payments.rejectTitle')}
        okText={t('payments.reject')}
        minLen={5}
        onOk={async (reason) => {
          const id = rejectId;
          setRejectId(null);
          if (id) surface(await mutate(`/api/admin/payments/${id}/reject`, { reason }));
        }}
        onCancel={() => setRejectId(null)}
      />
      </Space>
    </div>
  );
}

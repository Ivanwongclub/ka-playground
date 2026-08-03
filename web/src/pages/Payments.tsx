// S-UX3-2 — money mutations: record a manual payment (evidence upload, full amount, OD-5), then a
// SECOND finance person confirms/rejects it (BI-9). The confirm/reject controls are shown to EVERY
// finance.confirm holder — the UI never checks "did I record this?" to hide them; the server refuses a
// same-person confirm (403) and this UI surfaces it. Amounts via formatMoney. Refresh after mutate.
import { useState } from 'react';
import { App, Button, Card, Input, Modal, Space, Table, Typography, Upload } from 'antd';
import type { UploadFile } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { ReasonModal } from '../components/ReasonModal';
import { formatMoney } from '../display/money';
import { StatusTag } from '../display/status';
import { programmeName, personName } from '../display/names';

const { Title, Paragraph, Text } = Typography;

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
}

export function Payments() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
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
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('payments.title')}</Title>
        <Paragraph type="secondary">{t('payments.subtitle')}</Paragraph>
      </div>

      <DataBoundary loading={orders.loading} error={orders.error}>
        <Card title={t('payments.ordersAwaiting')}>
          <Table<OrderRow>
            rowKey="id"
            size="small"
            dataSource={awaiting}
            pagination={false}
            locale={{ emptyText: t('payments.noOrders') }}
            columns={[
              { title: t('payments.student'), render: (_, o) => personName(o.student_name) },
              { title: t('payments.programme'), render: (_, o) => programmeName(o, locale) },
              { title: t('payments.amount'), render: (_, o) => <Text strong>{formatMoney(o.total_amount_minor, o.currency, locale)}</Text> },
              { title: '', key: 'act', render: (_, o) => <Button size="small" type="primary" onClick={() => setRecordFor(o)}>{t('payments.record')}</Button> },
            ]}
          />
        </Card>
      </DataBoundary>

      <DataBoundary loading={payments.loading} error={payments.error}>
        <Card title={t('payments.pendingConfirmation')}>
          <Table<PaymentRow>
            rowKey="id"
            size="small"
            dataSource={pending}
            pagination={false}
            locale={{ emptyText: t('payments.noPending') }}
            columns={[
              { title: t('payments.student'), render: (_, p) => personName(p.student_name) },
              { title: t('payments.amount'), render: (_, p) => <Text strong>{formatMoney(p.amount_minor, p.currency, locale)}</Text> },
              // BI-9: the confirmer SEES who recorded it — so they know if it is (or isn't) themselves.
              { title: t('payments.recordedBy'), render: (_, p) => personName(p.recorded_by_name) },
              { title: '', dataIndex: 'status', render: (s: string) => <StatusTag domain="paymentStatus" value={s} /> },
              {
                title: '', key: 'act',
                render: (_, p) => (
                  <Space>
                    <Button size="small" type="primary" onClick={() => confirmPayment(p)}>{t('payments.confirm')}</Button>
                    <Button size="small" danger onClick={() => setRejectId(p.id)}>{t('payments.reject')}</Button>
                  </Space>
                ),
              },
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
  );
}

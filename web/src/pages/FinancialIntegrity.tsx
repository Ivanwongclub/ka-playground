// S04B audit element: Financial Integrity Report — every figure live from
// source (no cached totals). Academy finance/audit only.
import { useEffect, useState } from 'react';
import { Alert, Card, Space, Statistic, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';

const { Title, Text } = Typography;

interface Bucket { status?: string; origin?: string; destination_party?: string; n: number; minor: number }
interface Report {
  generated_at: string;
  live_from_source: boolean;
  orders: Bucket[];
  payments_by_origin: Bucket[];
  receipts: { count: number; sequences: { key: string; next_number: number }[] };
  refunds: Bucket[];
  refunds_by_destination: Bucket[];
  credit_notes: { count: number; minor: number };
  consolidated_invoices: { n: number; original_minor: number; balance_minor: number };
  obligations: { pending: number; consumed: number };
  invoice_credit_reconciliation: { credited_via_notes_minor: number; invoice_original_minus_balance_minor: number };
}

const hkd = (minor: number) => `$${(minor / 100).toLocaleString('en-HK', { minimumFractionDigits: 2 })}`;

export function FinancialIntegrity() {
  const { t } = useTranslation();
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    void authFetch('/api/reports/financial-integrity')
      .then(async (r) => (r.ok ? setReport((await r.json()) as Report) : setError(true)));
  }, []);

  if (error) return <Alert type="error" showIcon message={t('fin.loadError')} />;

  const amountTable = (rows: Bucket[], keyField: 'status' | 'origin' | 'destination_party', label: string) => (
    <Table<Bucket>
      rowKey={(r) => `${r[keyField]}-${r.status ?? ''}`}
      size="small"
      dataSource={rows}
      pagination={false}
      columns={[
        { title: label, dataIndex: keyField, render: (v: string) => <Tag>{v}</Tag> },
        ...(keyField === 'origin' ? [{ title: t('fin.status'), dataIndex: 'status' }] : []),
        { title: t('fin.count'), dataIndex: 'n' },
        { title: t('fin.amount'), dataIndex: 'minor', render: (v: number) => hkd(v) },
      ]}
    />
  );

  const recon = report?.invoice_credit_reconciliation;
  const reconOk = recon && recon.credited_via_notes_minor === recon.invoice_original_minus_balance_minor;

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Title level={3}>{t('fin.title')}</Title>
      {report && <Alert type="success" showIcon message={`${t('fin.live')} · ${report.generated_at}`} />}
      <Card title={t('fin.orders')}>{report && amountTable(report.orders, 'status', t('fin.status'))}</Card>
      <Card title={t('fin.payments')}>{report && amountTable(report.payments_by_origin, 'origin', t('fin.origin'))}</Card>
      <Space wrap style={{ width: '100%', alignItems: 'stretch' }}>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={t('fin.receipts')} value={report?.receipts.count ?? 0} /></Card>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={t('fin.creditNotes')} value={hkd(report?.credit_notes.minor ?? 0)} /></Card>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={`${t('fin.obligations')} · ${t('fin.pending')}`} value={report?.obligations.pending ?? 0} /></Card>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={`${t('fin.obligations')} · ${t('fin.consumed')}`} value={report?.obligations.consumed ?? 0} /></Card>
      </Space>
      <Card title={t('fin.refunds')}>{report && amountTable(report.refunds, 'status', t('fin.status'))}</Card>
      <Card title={t('fin.invoices')}>
        {report && (
          <Space size="large">
            <Statistic title={t('fin.count')} value={report.consolidated_invoices.n} />
            <Statistic title={t('fin.original')} value={hkd(report.consolidated_invoices.original_minor)} />
            <Statistic title={t('fin.balance')} value={hkd(report.consolidated_invoices.balance_minor)} />
          </Space>
        )}
      </Card>
      <Card title={t('fin.reconciliation')}>
        {recon && (
          <Space direction="vertical">
            <Text>{t('fin.creditedViaNotes')}: {hkd(recon.credited_via_notes_minor)}</Text>
            <Text>{t('fin.originalMinusBalance')}: {hkd(recon.invoice_original_minus_balance_minor)}</Text>
            <Tag color={reconOk ? 'green' : 'red'}>{reconOk ? 'OD-54 ✓' : 'OD-54 ✗'}</Tag>
          </Space>
        )}
      </Card>
    </Space>
  );
}

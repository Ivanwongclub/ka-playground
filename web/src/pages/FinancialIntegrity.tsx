// S04B audit element: Financial Integrity Report — every figure live from
// source (no cached totals). Academy finance/audit only.
import { useEffect, useState } from 'react';
import { Alert, Card, Space, Statistic, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';
import type { KaLocale } from '../i18n';
import { formatMoney } from '../display/money';
import { formatHkt } from '../display/date';
import { StatusTag } from '../display/status';
// DS2 (restyle rollout M2 — money tier). ALLOWED adopter (import-guard). Appearance only: the titled
// section cards → SubPanel framing; every figure, the recon comparison and StatusTag pills are byte-identical.
import { SubPanel } from '@/ds2';

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

export function FinancialIntegrity() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  // S-UX2a money: integer minor units ÷ 100 through Intl currency style — never a float, never a
  // hardcoded '$'/'en-HK'. Phase 1 is HKD (multi-currency is Phase 2 / OD-18).
  const hkd = (minor: number) => formatMoney(minor, 'HKD', locale);
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    void authFetch('/api/reports/financial-integrity')
      .then(async (r) => (r.ok ? setReport((await r.json()) as Report) : setError(true)));
  }, []);

  if (error) return <Alert type="error" showIcon message={t('fin.loadError')} />;

  const amountTable = (
    rows: Bucket[],
    keyField: 'status' | 'origin' | 'destination_party',
    label: string,
    domain: string,
  ) => (
    <Table<Bucket>
      rowKey={(r) => `${r[keyField]}-${r.status ?? ''}`}
      size="small"
      dataSource={rows}
      pagination={false}
      columns={[
        { title: label, dataIndex: keyField, render: (v: string) => <StatusTag domain={domain} value={v} /> },
        ...(keyField === 'origin'
          ? [{ title: t('fin.status'), dataIndex: 'status', render: (v: string) => <StatusTag domain="paymentStatus" value={v} /> }]
          : []),
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
      {report && <Alert type="success" showIcon message={`${t('fin.live')} · ${formatHkt(report.generated_at, locale)}`} />}
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('fin.orders')}</Title>
        {report && amountTable(report.orders, 'status', t('fin.status'), 'orderStatus')}
      </SubPanel>
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('fin.payments')}</Title>
        {report && amountTable(report.payments_by_origin, 'origin', t('fin.origin'), 'paymentOrigin')}
      </SubPanel>
      <Space wrap style={{ width: '100%', alignItems: 'stretch' }}>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={t('fin.receipts')} value={report?.receipts.count ?? 0} /></Card>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={t('fin.creditNotes')} value={hkd(report?.credit_notes.minor ?? 0)} /></Card>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={`${t('fin.obligations')} · ${t('fin.pending')}`} value={report?.obligations.pending ?? 0} /></Card>
        <Card size="small" style={{ minWidth: 200 }}><Statistic title={`${t('fin.obligations')} · ${t('fin.consumed')}`} value={report?.obligations.consumed ?? 0} /></Card>
      </Space>
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('fin.refunds')}</Title>
        {report && amountTable(report.refunds, 'status', t('fin.status'), 'refundStatus')}
      </SubPanel>
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('fin.invoices')}</Title>
        {report && (
          <Space size="large">
            <Statistic title={t('fin.count')} value={report.consolidated_invoices.n} />
            <Statistic title={t('fin.original')} value={hkd(report.consolidated_invoices.original_minor)} />
            <Statistic title={t('fin.balance')} value={hkd(report.consolidated_invoices.balance_minor)} />
          </Space>
        )}
      </SubPanel>
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('fin.reconciliation')}</Title>
        {recon && (
          <Space direction="vertical">
            <Text>{t('fin.creditedViaNotes')}: {hkd(recon.credited_via_notes_minor)}</Text>
            <Text>{t('fin.originalMinusBalance')}: {hkd(recon.invoice_original_minus_balance_minor)}</Text>
            <Tag color={reconOk ? 'green' : 'red'}>{reconOk ? t('fin.reconciled') : t('fin.notReconciled')}</Tag>
          </Space>
        )}
      </SubPanel>
    </Space>
  );
}

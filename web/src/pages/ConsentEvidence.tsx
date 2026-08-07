// S03 audit element: Consent Evidence Report — coverage by version AND
// language, status lists, per-signature evidence bundle export. audit_read.
// S-UX2a: shared fetch convention, StatusTag for language, formatHkt, names.
// DS2 rollout D2 — markup-only restyle (Card→SubPanel, stat→token). READ-ONLY:
// the only action is the evidence-bundle DOWNLOAD (a GET export, no mutation) —
// its onClick handler is kept BYTE-IDENTICAL (see the diff).
import { Alert, Button, Space, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch, getToken } from '../auth/session';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { programmeName, personName } from '../display/names';
import { formatHkt } from '../display/date';
import { StatusTag } from '../display/status';
import { SubPanel, MetaChip } from '@/ds2';

const { Title } = Typography;

interface Coverage {
  template_id: string;
  language: string;
  version: number;
  is_placeholder: boolean;
  signatures: number;
  active: number;
  superseded_or_voided: number;
}

interface RequestRow {
  id: string;
  programme_id: number;
  student_id: number;
  signer_id: number;
  status: string;
  programme_name_en: string | null;
  programme_name_tc: string | null;
  programme_name_sc: string | null;
  student_name: string | null;
  signer_name: string | null;
}

interface Report {
  coverage_by_version_and_language: Coverage[];
  outstanding: RequestRow[];
  declined: RequestRow[];
  superseded: RequestRow[];
  voided: RequestRow[];
  placeholder_signatures: number;
}

interface SignatureRow {
  id: string;
  request_id: string;
  language: string;
  signed_at: string;
  signer_name: string | null;
}

export function ConsentEvidence() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { data: report, loading, error } = useResource<Report>('/api/reports/consent-evidence');
  const { data: sigData } = useResource<{ data: SignatureRow[] }>('/api/consent-signatures');
  const signatures = sigData?.data ?? [];

  const statusTable = (rows: RequestRow[], title: string) => (
    <div style={{ flex: 1, minWidth: 260 }}>
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{title}</Title>
        <Table<RequestRow>
          rowKey="id"
          size="small"
          dataSource={rows}
          pagination={false}
          columns={[
            { title: t('consent.evidence.request'), dataIndex: 'id', render: (v: string) => `${v.slice(0, 13)}…` },
            { title: t('consent.programme'), render: (_, row) => programmeName(row, locale) },
            { title: t('consent.student'), render: (_, row) => personName(row.student_name) },
          ]}
        />
      </SubPanel>
    </div>
  );

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Title level={3}>{t('consent.evidence.title')}</Title>
      <DataBoundary loading={loading} error={error}>
        <Space direction="vertical" size="large" style={{ width: '100%' }}>
          {report && report.placeholder_signatures > 0 && (
            <Alert type="warning" showIcon message={`${t('consent.evidence.placeholderCount')}: ${report.placeholder_signatures} (R15)`} />
          )}
          <SubPanel tone="neutral">
            <Title level={5} style={{ marginTop: 0 }}>{t('consent.evidence.coverage')}</Title>
            <Table<Coverage>
              rowKey={(r) => `${r.template_id}-${r.language}-${r.version}`}
              size="small"
              dataSource={report?.coverage_by_version_and_language ?? []}
              pagination={false}
              columns={[
                { title: t('consent.evidence.language'), dataIndex: 'language', render: (v: string) => <StatusTag domain="language" value={v} /> },
                { title: t('consent.evidence.version'), dataIndex: 'version' },
                { title: t('consent.evidence.signatures'), dataIndex: 'signatures' },
                { title: t('consent.evidence.active'), dataIndex: 'active' },
                {
                  title: t('consent.r15'), dataIndex: 'is_placeholder',
                  render: (v: boolean) => (v ? <Tag color="red">{t('consent.r15')}</Tag> : null),
                },
              ]}
            />
          </SubPanel>
          <SubPanel tone="neutral">
            <Title level={5} style={{ marginTop: 0 }}>{t('consent.evidence.download')}</Title>
            <Table<SignatureRow>
              rowKey="id"
              size="small"
              dataSource={signatures}
              pagination={false}
              columns={[
                { title: t('consent.evidence.request'), dataIndex: 'request_id', render: (v: string) => `${v.slice(0, 13)}…` },
                { title: t('consent.evidence.language'), dataIndex: 'language', render: (v: string) => <StatusTag domain="language" value={v} /> },
                { title: t('consent.signedAt'), dataIndex: 'signed_at', render: (v: string) => formatHkt(v, locale) },
                {
                  title: '', dataIndex: 'id',
                  render: (id: string) => (
                    <Button
                      size="small"
                      onClick={() => {
                        void authFetch(`/api/reports/consent-evidence/${id}/bundle`)
                          .then(async (r) => {
                            if (!r.ok || !getToken()) return;
                            const url = URL.createObjectURL(await r.blob());
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `consent-evidence-${id}.zip`;
                            a.click();
                            URL.revokeObjectURL(url);
                          });
                      }}
                    >
                      {t('consent.evidence.download')}
                    </Button>
                  ),
                },
              ]}
            />
          </SubPanel>
          <Space wrap style={{ width: '100%', alignItems: 'stretch' }}>
            {report && statusTable(report.outstanding, t('consent.evidence.outstanding'))}
            {report && statusTable(report.declined, t('consent.evidence.declined'))}
            {report && statusTable(report.superseded, t('consent.evidence.superseded'))}
            {report && statusTable(report.voided, t('consent.evidence.voided'))}
          </Space>
          <SubPanel tone="neutral">
            <MetaChip>{t('consent.evidence.signatures')}</MetaChip>
            <div className="ka-dash-kpi__value">{signatures.length}</div>
          </SubPanel>
        </Space>
      </DataBoundary>
    </Space>
  );
}

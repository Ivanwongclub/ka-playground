// S03 audit element: Consent Evidence Report — coverage by version AND
// language, status lists, per-signature evidence bundle export. audit_read.
import { useEffect, useState } from 'react';
import { Alert, Button, Card, Space, Statistic, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch, getToken } from '../auth/session';

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
}

export function ConsentEvidence() {
  const { t } = useTranslation();
  const [report, setReport] = useState<Report | null>(null);
  const [signatures, setSignatures] = useState<SignatureRow[]>([]);
  const [error, setError] = useState(false);

  useEffect(() => {
    void authFetch('/api/reports/consent-evidence')
      .then(async (r) => (r.ok ? setReport((await r.json()) as Report) : setError(true)));
    void authFetch('/api/consent-signatures')
      .then(async (r) => r.ok && setSignatures(((await r.json()) as { data: SignatureRow[] }).data));
  }, []);

  if (error) {
    return <Alert type="error" showIcon message={t('consent.loadError')} />;
  }

  const statusTable = (rows: RequestRow[], title: string) => (
    <Card size="small" title={title} style={{ flex: 1, minWidth: 260 }}>
      <Table<RequestRow>
        rowKey="id"
        size="small"
        dataSource={rows}
        pagination={false}
        columns={[
          { title: t('consent.evidence.request'), dataIndex: 'id', render: (v: string) => `${v.slice(0, 13)}…` },
          { title: t('consent.programme'), dataIndex: 'programme_id' },
          { title: t('consent.student'), dataIndex: 'student_id' },
        ]}
      />
    </Card>
  );

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Title level={3}>{t('consent.evidence.title')}</Title>
      {report && report.placeholder_signatures > 0 && (
        <Alert type="warning" showIcon message={`${t('consent.evidence.placeholderCount')}: ${report.placeholder_signatures} (R15)`} />
      )}
      <Card title={t('consent.evidence.coverage')}>
        <Table<Coverage>
          rowKey={(r) => `${r.template_id}-${r.language}-${r.version}`}
          size="small"
          dataSource={report?.coverage_by_version_and_language ?? []}
          pagination={false}
          columns={[
            { title: t('consent.evidence.language'), dataIndex: 'language', render: (v: string) => <Tag>{v}</Tag> },
            { title: t('consent.evidence.version'), dataIndex: 'version' },
            { title: t('consent.evidence.signatures'), dataIndex: 'signatures' },
            { title: t('consent.evidence.active'), dataIndex: 'active' },
            {
              title: t('consent.r15'), dataIndex: 'is_placeholder',
              render: (v: boolean) => (v ? <Tag color="red">{t('consent.r15')}</Tag> : null),
            },
          ]}
        />
      </Card>
      <Card title={t('consent.evidence.download')}>
        <Table<SignatureRow>
          rowKey="id"
          size="small"
          dataSource={signatures}
          pagination={false}
          columns={[
            { title: t('consent.evidence.request'), dataIndex: 'request_id', render: (v: string) => `${v.slice(0, 13)}…` },
            { title: t('consent.evidence.language'), dataIndex: 'language', render: (v: string) => <Tag>{v}</Tag> },
            { title: t('consent.signedAt'), dataIndex: 'signed_at' },
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
      </Card>
      <Space wrap style={{ width: '100%', alignItems: 'stretch' }}>
        {report && statusTable(report.outstanding, t('consent.evidence.outstanding'))}
        {report && statusTable(report.declined, t('consent.evidence.declined'))}
        {report && statusTable(report.superseded, t('consent.evidence.superseded'))}
        {report && statusTable(report.voided, t('consent.evidence.voided'))}
      </Space>
      {report && (
        <Statistic title={t('consent.evidence.signatures')} value={signatures.length} />
      )}
    </Space>
  );
}

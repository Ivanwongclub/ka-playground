// S03: consent template admin view — versions per language with the R15
// placeholder banner (§12.3) whenever live versions carry placeholder text.
// S-UX2a: shared fetch convention; StatusTag for version status + language.
import { useEffect, useState } from 'react';
import { Alert, Card, Select, Space, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useResource, DataBoundary } from '../api/useResource';
import { StatusTag } from '../display/status';

const { Title, Text } = Typography;

interface Template {
  id: string;
  name_en: string;
  name_tc: string;
  name_sc: string;
}

interface Version {
  id: string;
  language: string;
  version: number;
  status: string;
  is_placeholder: boolean;
  sha256: string | null;
}

export function AdminConsentTemplates() {
  const { t, i18n } = useTranslation();
  const [selected, setSelected] = useState<string | null>(null);

  const { data: tData, loading: tLoading, error: tError } = useResource<{ data: Template[] }>('/api/admin/consent-templates');
  const templates = tData?.data ?? [];
  useEffect(() => {
    if (selected === null && templates.length > 0) setSelected(templates[0].id);
  }, [templates, selected]);

  const { data: vData, loading: vLoading, error: vError } = useResource<{ data: Version[] }>(
    selected ? `/api/consent-templates/${selected}/versions` : '',
  );
  const versions = vData?.data ?? [];

  const nameFor = (row: Template) =>
    i18n.language === 'zh-TC' ? row.name_tc : i18n.language === 'zh-SC' ? row.name_sc : row.name_en;
  const hasPlaceholder = versions.some((v) => v.is_placeholder && v.status === 'published');

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Title level={3}>{t('consent.templates.title')}</Title>
      <DataBoundary loading={tLoading} error={tError} empty={templates.length === 0}>
        <Select
          value={selected}
          onChange={setSelected}
          style={{ minWidth: 320 }}
          options={templates.map((row) => ({ value: row.id, label: nameFor(row) }))}
        />
        {hasPlaceholder && (
          <Alert type="warning" showIcon message={t('consent.templates.placeholderAdminBanner')} style={{ margin: '16px 0' }} />
        )}
        <Card title={t('consent.templates.versions')} style={{ marginTop: 16 }}>
          <DataBoundary loading={vLoading} error={vError} empty={versions.length === 0}>
            <Table<Version>
              rowKey="id"
              size="small"
              dataSource={versions}
              pagination={false}
              columns={[
                { title: t('consent.evidence.language'), dataIndex: 'language', render: (v: string) => <StatusTag domain="language" value={v} /> },
                { title: t('consent.evidence.version'), dataIndex: 'version' },
                { title: t('consent.evidence.statusCol'), dataIndex: 'status', render: (v: string) => <StatusTag domain="templateStatus" value={v} /> },
                {
                  title: t('consent.r15'), dataIndex: 'is_placeholder',
                  render: (v: boolean) => (v ? <Tag color="red">{t('consent.r15')}</Tag> : null),
                },
                { title: 'SHA-256', dataIndex: 'sha256', render: (v: string | null) => (v ? <Text code>{v.slice(0, 16)}…</Text> : null) },
              ]}
            />
          </DataBoundary>
        </Card>
      </DataBoundary>
    </Space>
  );
}

// S03: consent template admin view — versions per language with the R15
// placeholder banner (§12.3) whenever live versions carry placeholder text.
import { useEffect, useState } from 'react';
import { Alert, Card, Select, Space, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';

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
  const [templates, setTemplates] = useState<Template[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [versions, setVersions] = useState<Version[]>([]);

  useEffect(() => {
    void authFetch('/api/admin/consent-templates')
      .then(async (r) => {
        if (!r.ok) return;
        const rows = ((await r.json()) as { data: Template[] }).data;
        setTemplates(rows);
        if (rows.length > 0) setSelected(rows[0].id);
      });
  }, []);

  useEffect(() => {
    if (selected === null) return;
    void authFetch(`/api/consent-templates/${selected}/versions`)
      .then(async (r) => r.ok && setVersions(((await r.json()) as { data: Version[] }).data));
  }, [selected]);

  const nameFor = (row: Template) =>
    i18n.language === 'zh-TC' ? row.name_tc : i18n.language === 'zh-SC' ? row.name_sc : row.name_en;
  const hasPlaceholder = versions.some((v) => v.is_placeholder && v.status === 'published');

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Title level={3}>{t('consent.templates.title')}</Title>
      <Select
        value={selected}
        onChange={setSelected}
        style={{ minWidth: 320 }}
        options={templates.map((row) => ({ value: row.id, label: nameFor(row) }))}
      />
      {hasPlaceholder && (
        <Alert type="warning" showIcon message={t('consent.templates.placeholderAdminBanner')} />
      )}
      <Card title={t('consent.templates.versions')}>
        <Table<Version>
          rowKey="id"
          size="small"
          dataSource={versions}
          pagination={false}
          columns={[
            { title: t('consent.evidence.language'), dataIndex: 'language', render: (v: string) => <Tag>{v}</Tag> },
            { title: t('consent.evidence.version'), dataIndex: 'version' },
            { title: t('consent.evidence.statusCol'), dataIndex: 'status' },
            {
              title: t('consent.r15'), dataIndex: 'is_placeholder',
              render: (v: boolean) => (v ? <Tag color="red">{t('consent.r15')}</Tag> : null),
            },
            { title: 'SHA-256', dataIndex: 'sha256', render: (v: string | null) => (v ? <Text code>{v.slice(0, 16)}…</Text> : null) },
          ]}
        />
      </Card>
    </Space>
  );
}

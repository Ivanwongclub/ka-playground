// S04A step 6: E5 timeline reshaped for team-based capacity — the pool is a
// state; each enrolment renders its journey with the current stage highlighted.
// S-UX2a: names (S-UX2b fields) instead of raw FK ids; shared fetch convention.
import { Card, Space, Steps, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { programmeName, personName } from '../display/names';

const { Title, Paragraph } = Typography;

interface Row {
  id: string;
  programme_id: number;
  student_id: number;
  acting_guardian_id: number;
  status: string;
  created_at: string;
  programme_name_en: string | null;
  programme_name_tc: string | null;
  programme_name_sc: string | null;
  student_name: string | null;
  acting_guardian: string | null;
}

const JOURNEY = ['submitted', 'pending_consent', 'in_pool', 'teamed', 'confirmed', 'active', 'completed'];
const TERMINAL_BAD = ['withdrawn', 'released'];

export function Enrolments() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { data, loading, error } = useResource<{ data: Row[] }>('/api/enrolments');
  const rows = data?.data ?? [];

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Card>
        <Title level={3}>{t('enrol.listTitle')}</Title>
        <Paragraph type="secondary">{t('enrol.listCaption')}</Paragraph>
        <DataBoundary loading={loading} error={error} empty={rows.length === 0}>
          <Table<Row>
            rowKey="id"
            dataSource={rows}
            pagination={false}
            columns={[
              { title: t('enrol.programme'), render: (_, row) => programmeName(row, locale) },
              { title: t('enrol.student'), render: (_, row) => personName(row.student_name) },
              {
                title: '', dataIndex: 'status',
                render: (s: string) => (
                  <Tag color={TERMINAL_BAD.includes(s) ? 'red' : s === 'completed' ? 'green' : 'gold'}>
                    {t(`enrol.status.${s}`)}
                  </Tag>
                ),
              },
            ]}
            expandable={{
              expandedRowRender: (row) => TERMINAL_BAD.includes(row.status)
                ? <Tag color="red">{t(`enrol.status.${row.status}`)}</Tag>
                : (
                  <Steps
                    size="small"
                    current={JOURNEY.indexOf(row.status)}
                    items={JOURNEY.map((s) => ({ title: t(`enrol.status.${s}`) }))}
                  />
                ),
            }}
          />
        </DataBoundary>
      </Card>
    </Space>
  );
}

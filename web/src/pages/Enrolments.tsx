// S04A step 6: E5 timeline reshaped for team-based capacity — the pool is a
// state; each enrolment renders its journey with the current stage highlighted.
// S-UX2a: names (S-UX2b fields) instead of raw FK ids; shared fetch convention.
import { Space, Steps, Table, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { programmeName, personName } from '../display/names';
import { SubPanel, StateBadge } from '@/ds2'; // DS2 rollout D1 — markup-only restyle (read-only surface, no mutation)

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
      <SubPanel tone="neutral">
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
                title: t('common.status'), dataIndex: 'status',
                // DS2: state as a VISUAL (StateBadge) + the SAME localized label (enrol.status.*) — same
                // three states as the old Tag colours (bad→warn · completed→ok · in-progress→action).
                render: (s: string) => (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <StateBadge state={TERMINAL_BAD.includes(s) ? 'warn' : s === 'completed' ? 'ok' : 'action'} title={t(`enrol.status.${s}`)} />
                    {t(`enrol.status.${s}`)}
                  </span>
                ),
              },
            ]}
            expandable={{
              expandedRowRender: (row) => TERMINAL_BAD.includes(row.status)
                ? (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <StateBadge state="warn" title={t(`enrol.status.${row.status}`)} />
                    {t(`enrol.status.${row.status}`)}
                  </span>
                )
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
      </SubPanel>
    </Space>
  );
}

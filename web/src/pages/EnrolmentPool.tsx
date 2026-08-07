// S04A audit element: Enrolment & Pool Report (audit_read).
import { useEffect, useState } from 'react';
import { Alert, Space, Table, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';
import { formatHktDate } from '../display/date';
import { StatusTag } from '../display/status';
import { SubPanel, StateBadge } from '@/ds2'; // DS2 rollout D2 — markup-only restyle (read-only audit report)

const { Title } = Typography;

interface PoolRow {
  programme_id: number;
  code: string;
  pending_consent: number;
  in_pool: number;
  teamed_plus: number;
  terminal: number;
  formation_deadline_on: string | null;
}

interface TimelineRow {
  id: string;
  programme_id: number;
  status: string;
  student_name: string;
  acting_guardian: string;
  created_at: string;
}

interface WithdrawalRow {
  id: string;
  enrolment_id: string;
  status: string;
  reason: string;
  decided_by_name: string | null;
}

interface Report {
  pool_by_programme: PoolRow[];
  timelines: TimelineRow[];
  issuance_gaps: string[];
  withdrawal_pipeline: WithdrawalRow[];
}

export function EnrolmentPool() {
  const { t, i18n } = useTranslation();
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    void authFetch('/api/reports/enrolment-pool')
      .then(async (r) => (r.ok ? setReport((await r.json()) as Report) : setError(true)));
  }, []);

  if (error) return <Alert type="error" showIcon message={t('consent.loadError')} />;

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Title level={3}>{t('enrol.pool.title')}</Title>
      {report && (report.issuance_gaps.length > 0
        ? <Alert type="error" showIcon message={`${t('enrol.pool.issuanceGaps')}: ${report.issuance_gaps.length}`} />
        : <Alert type="success" showIcon message={t('enrol.pool.issuanceHealthy')} />)}
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('enrol.pool.byProgramme')}</Title>
        <Table<PoolRow>
          rowKey="programme_id"
          size="small"
          dataSource={report?.pool_by_programme ?? []}
          pagination={false}
          columns={[
            { title: t('enrol.programme'), dataIndex: 'code' },
            { title: t('enrol.pool.pendingConsent'), dataIndex: 'pending_consent' },
            { title: t('enrol.pool.inPool'), dataIndex: 'in_pool' },
            { title: t('enrol.pool.teamedPlus'), dataIndex: 'teamed_plus' },
            { title: t('enrol.pool.terminal'), dataIndex: 'terminal' },
            { title: t('enrol.pool.deadline'), dataIndex: 'formation_deadline_on', render: (v: string | null) => formatHktDate(v, i18n.language) },
          ]}
        />
      </SubPanel>
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('enrol.pool.timelines')}</Title>
        <Table<TimelineRow>
          rowKey="id"
          size="small"
          dataSource={report?.timelines ?? []}
          pagination={{ pageSize: 10 }}
          columns={[
            // S-UX2a: the timelines endpoint returns student_name + acting_guardian but only a raw
            // programme_id (no name) — S-UX2b did not cover this report's timelines. Rather than add a
            // backend field under a frontend card, the redundant programme_id column is dropped (the
            // pool-by-programme table above already gives the per-programme breakdown). Flagged for a
            // ruling if the programme should be shown here (needs a programme join in the report).
            { title: t('enrol.student'), dataIndex: 'student_name' },
            { title: t('enrol.actingGuardian'), dataIndex: 'acting_guardian' },
            { title: '', dataIndex: 'status', render: (s: string) => <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><StateBadge state={s === 'completed' ? 'ok' : ['withdrawn', 'released'].includes(s) ? 'warn' : 'action'} title={t(`enrol.status.${s}`)} />{t(`enrol.status.${s}`)}</span> },
          ]}
        />
      </SubPanel>
      <SubPanel tone="neutral">
        <Title level={5} style={{ marginTop: 0 }}>{t('enrol.pool.withdrawals')}</Title>
        <Table<WithdrawalRow>
          rowKey="id"
          size="small"
          dataSource={report?.withdrawal_pipeline ?? []}
          pagination={false}
          columns={[
            { title: t('enrol.pool.reason'), dataIndex: 'reason' },
            { title: '', dataIndex: 'status', render: (s: string) => <StatusTag domain="withdrawalStatus" value={s} /> },
            { title: t('enrol.pool.decidedBy'), dataIndex: 'decided_by_name' },
          ]}
        />
      </SubPanel>
    </Space>
  );
}

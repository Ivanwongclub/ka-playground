// S01 audit element: Access & Identity Report — invitation funnel, auth log,
// links per student, sole-guardian list, replacement exceptions, capability log.
import { useEffect, useState } from 'react';
import { Alert, Card, Col, Row, Statistic, Table, Tag, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';
import { kaColors } from '../theme/theme';
import { formatHkt } from '../display/date';
import { humanise } from '../display/status';

const { Title, Paragraph } = Typography;

interface Report {
  funnel: { issued: number; accepted: number; verified: number };
  auth_events: { occurred_at: string; action: string; actor_id: number | null; actor_name: string | null; actor_role: string | null; reason: string | null }[];
  links_per_student: { student_id: number; name: string; active_links: number }[];
  sole_guardian_students: { student_id: number; name: string }[];
  replacement_exceptions: { id: string; student_id: number; student_name: string | null; reason: string; deadline: string }[];
  capability_log: { occurred_at: string; action: string; actor_id: number | null; actor_name: string | null; reason: string | null }[];
  onboarding: {
    funnel: { submitted: number; approved: number; declined: number; verified: number };
    queue: { pending_accounts: number; pending_links: number; held: number; open_escalations: number; oldest_pending_days: number };
    held_link_ledger: { held: number; materialised: number; expired: number };
  };
}

export function AccessIdentity() {
  const { t, i18n } = useTranslation();
  const hkt = (iso: string) => formatHkt(iso, i18n.language);
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    void (async () => {
      try {
        const res = await authFetch('/api/reports/access-identity');
        if (!res.ok) throw new Error(String(res.status));
        setReport((await res.json()) as Report);
      } catch {
        setError(true);
      }
    })();
  }, []);

  if (error) return <Alert type="error" showIcon message={t('audit.loadError')} />;
  if (!report) return <div className="ka-route-loading" aria-hidden />;

  return (
    <div style={{ maxWidth: 1100 }}>
      <Title level={1}>{t('accessReport.title')}</Title>
      <Paragraph type="secondary">{t('accessReport.subtitle')}</Paragraph>

      <Title level={2}>{t('accessReport.funnel')}</Title>
      <Row gutter={16}>
        {(['issued', 'accepted', 'verified'] as const).map((stage) => (
          <Col key={stage} xs={24} sm={8}>
            <Card>
              <Statistic
                title={t(`accessReport.${stage}`)}
                value={report.funnel[stage]}
                valueStyle={{ color: kaColors.gold, fontVariantNumeric: 'tabular-nums' }}
              />
            </Card>
          </Col>
        ))}
      </Row>

      <Title level={2}>{t('accessReport.onboarding')}</Title>
      <Paragraph type="secondary">{t('accessReport.onboardingSub')}</Paragraph>
      <Row gutter={16}>
        {(['submitted', 'approved', 'verified'] as const).map((stage) => (
          <Col key={stage} xs={12} sm={6}>
            <Card>
              <Statistic title={t(`accessReport.onb_${stage}`)} value={report.onboarding.funnel[stage]}
                valueStyle={{ color: kaColors.gold, fontVariantNumeric: 'tabular-nums' }} />
            </Card>
          </Col>
        ))}
        <Col xs={12} sm={6}>
          <Card>
            <Statistic title={t('accessReport.onb_escalations')} value={report.onboarding.queue.open_escalations}
              valueStyle={{ color: report.onboarding.queue.open_escalations > 0 ? kaColors.danger : kaColors.gold, fontVariantNumeric: 'tabular-nums' }} />
          </Card>
        </Col>
      </Row>
      <Row gutter={16} style={{ marginTop: 16 }}>
        {([['pendingAccounts', report.onboarding.queue.pending_accounts], ['pendingLinks', report.onboarding.queue.pending_links], ['heldClaims', report.onboarding.queue.held], ['oldestPending', report.onboarding.queue.oldest_pending_days]] as const).map(([key, val]) => (
          <Col key={key} xs={12} sm={6}>
            <Card>
              <Statistic title={t(`accessReport.${key}`)} value={val as number}
                valueStyle={{ fontVariantNumeric: 'tabular-nums' }} />
            </Card>
          </Col>
        ))}
      </Row>
      <Paragraph type="secondary" style={{ marginTop: 12 }}>
        {t('accessReport.heldLedger', {
          held: report.onboarding.held_link_ledger.held,
          materialised: report.onboarding.held_link_ledger.materialised,
          expired: report.onboarding.held_link_ledger.expired,
        })}
      </Paragraph>

      <Title level={2}>{t('accessReport.authLog')}</Title>
      <Table
        rowKey={(r) => `${r.occurred_at}${r.action}${r.actor_id ?? ''}`}
        dataSource={report.auth_events}
        pagination={{ pageSize: 10, showSizeChanger: false }}
        columns={[
          { title: t('audit.colTime'), dataIndex: 'occurred_at', width: 150, render: hkt },
          { title: t('audit.colAction'), dataIndex: 'action', render: (a: string) => <Tooltip title={a}><Tag color={a.includes('fail') || a === 'lockout' ? 'error' : 'default'}>{humanise(a)}</Tag></Tooltip> },
          // S-UX2b-f: actor_name; null (system actor / since-deleted user) → "System".
          { title: t('audit.colActor'), dataIndex: 'actor_name', width: 150, render: (v: string | null) => v ?? t('audit.system') },
          { title: t('audit.colReason'), dataIndex: 'reason', render: (v: string | null) => v ?? '—' },
        ]}
      />

      <Title level={2}>{t('accessReport.links')}</Title>
      <Table
        rowKey="student_id"
        dataSource={report.links_per_student}
        pagination={false}
        columns={[
          { title: t('accessReport.student'), dataIndex: 'name' },
          { title: t('accessReport.activeLinks'), dataIndex: 'active_links', align: 'right', className: 'ka-tabular-nums' },
        ]}
      />

      <Title level={2}>{t('accessReport.soleGuardian')}</Title>
      {report.sole_guardian_students.length === 0 ? (
        <Paragraph type="secondary">{t('accessReport.none')}</Paragraph>
      ) : (
        report.sole_guardian_students.map((s) => <Tag key={s.student_id}>{s.name}</Tag>)
      )}

      <Title level={2}>{t('accessReport.exceptions')}</Title>
      {report.replacement_exceptions.length === 0 ? (
        <Paragraph type="secondary">{t('accessReport.none')}</Paragraph>
      ) : (
        <Table
          rowKey="id"
          dataSource={report.replacement_exceptions}
          pagination={false}
          columns={[
            { title: t('accessReport.student'), dataIndex: 'student_name', width: 160, render: (v: string | null) => v ?? '—' }, // S-UX2b-f
            { title: t('audit.colReason'), dataIndex: 'reason' },
            { title: t('accessReport.deadline'), dataIndex: 'deadline', width: 150, render: hkt },
          ]}
        />
      )}

      <Title level={2}>{t('accessReport.capabilityLog')}</Title>
      <Table
        rowKey={(r) => `${r.occurred_at}${r.action}`}
        dataSource={report.capability_log}
        pagination={{ pageSize: 10, showSizeChanger: false }}
        columns={[
          { title: t('audit.colTime'), dataIndex: 'occurred_at', width: 150, render: hkt },
          { title: t('audit.colAction'), dataIndex: 'action', render: (a: string) => <Tooltip title={a}><Tag color={a.includes('refused') ? 'error' : 'success'}>{humanise(a)}</Tag></Tooltip> },
          { title: t('accessReport.grantor'), dataIndex: 'actor_name', width: 150, render: (v: string | null) => v ?? t('audit.system') }, // S-UX2b-f
          { title: t('audit.colReason'), dataIndex: 'reason', render: (v: string | null) => v ?? '—' },
        ]}
      />
    </div>
  );
}

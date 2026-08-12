// R1-F2 — PROGRAMME 360 (/admin/programmes/{id}/overview). The CRM's programme noun-page: a header + the
// sections that a caller's OWN gated reads honestly serve. RLS is the authority — this composes, never
// widens. Gated ops ∨ audit ∨ config (server, in-controller). Section visibility is CAPABILITY-HONEST: a
// section whose read the caller cannot serve renders a LINK ONWARD, never a stub. Financial Integrity is
// academy-wide → always a link, never embedded.
import { Space, Table, Tag, Typography } from 'antd';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { useResource, DataBoundary } from '../api/useResource';
import { formatHkt } from '../display/date';
import { SubPanel, EmptyState } from '@/ds2';

const { Title, Text, Paragraph } = Typography;

interface Overview { id: number; code: string; name_en: string; name_tc: string; name_sc: string; status: string; enrolment_opens_at: string | null; enrolment_closes_at: string | null }
interface StatusCount { status: string; n: number }
interface SessionRow { id: string; title: string; starts_at: string; ends_at: string; status: string }
interface TeamRow { id: string; programme_id: number; name: string; status: string; member_count: number }

function triName(r: { name_en: string; name_tc: string; name_sc: string }, locale: KaLocale): string {
  return (locale === 'zh-TC' ? r.name_tc : locale === 'zh-SC' ? r.name_sc : r.name_en) || r.name_en;
}

export function Programme360() {
  const { id } = useParams();
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { has } = useIdentity();
  // The funnel/teams reads need enrolment/team RLS — only opsAudit (operations ∨ audit) serves them; a
  // config-only admin's RLS would return nothing, so those sections link onward instead of showing zeros.
  const canEnrol = has('operations.manage') || has('audit.read');
  const canOps = has('operations.manage');

  const overview = useResource<Overview>(`/api/admin/programmes/${id}/overview`);
  const funnel = useResource<{ by_status: StatusCount[] }>(canEnrol ? `/api/admin/programmes/${id}/enrolment-summary` : null);
  const sessions = useResource<{ sessions: SessionRow[] }>(canOps ? `/api/admin/programmes/${id}/attendance-report` : null);
  const teams = useResource<{ data: TeamRow[] }>(canEnrol ? '/api/teams' : null);
  const progTeams = (teams.data?.data ?? []).filter((tm) => tm.programme_id === Number(id));
  const o = overview.data;

  return (
    <div data-density="admin">
      <Space direction="vertical" size="large" style={{ width: '100%' }}>
        {/* HEADER — trilingual name, code, status, enrolment window (display columns only, no config). */}
        <DataBoundary loading={overview.loading} error={overview.error}>
          <SubPanel tone="neutral">
            <Title level={3} style={{ margin: 0 }}>{o ? triName(o, locale) : ''}</Title>
            <div style={{ display: 'inline-flex', gap: 10, alignItems: 'center', flexWrap: 'wrap', marginTop: 6 }}>
              {o && <Text type="secondary">{o.code}</Text>}
              {o && <Tag>{o.status}</Tag>}
              {o?.enrolment_opens_at && <Text type="secondary">{t('programme360.enrolWindow', { from: formatHkt(o.enrolment_opens_at, locale), to: o.enrolment_closes_at ? formatHkt(o.enrolment_closes_at, locale) : '—' })}</Text>}
            </div>
          </SubPanel>
        </DataBoundary>

        {/* ENROLMENT FUNNEL — counts by status (ops/audit); config-only links onward to the Enrolment Pool. */}
        <SubPanel tone="neutral">
          <Title level={5} style={{ marginTop: 0 }}>{t('programme360.funnel')}</Title>
          {canEnrol ? (
            <DataBoundary loading={funnel.loading} error={funnel.error} empty={(funnel.data?.by_status.length ?? 0) === 0} emptySize="inline">
              <Table<StatusCount>
                rowKey="status" size="small" pagination={false} dataSource={funnel.data?.by_status ?? []}
                columns={[
                  { title: t('programme360.status'), dataIndex: 'status', render: (v: string) => <Tag>{v}</Tag> },
                  { title: t('programme360.count'), dataIndex: 'n', align: 'right' as const },
                ]}
              />
            </DataBoundary>
          ) : (
            <Link to="/admin/enrolment-pool">{t('programme360.openPool')}</Link>
          )}
        </SubPanel>

        {/* SESSIONS — ops embed via the attendance-report read; otherwise a link onward. */}
        <SubPanel tone="neutral">
          <Title level={5} style={{ marginTop: 0 }}>{t('programme360.sessions')}</Title>
          {canOps ? (
            <DataBoundary loading={sessions.loading} error={sessions.error} empty={(sessions.data?.sessions.length ?? 0) === 0} emptySize="inline">
              <Table<SessionRow>
                rowKey="id" size="small" pagination={false} dataSource={sessions.data?.sessions ?? []}
                columns={[
                  { title: t('programme360.session'), dataIndex: 'title' },
                  { title: t('programme360.when'), key: 'when', render: (_, s) => formatHkt(s.starts_at, locale) },
                  { title: t('programme360.status'), dataIndex: 'status', render: (v: string) => <Tag>{v}</Tag> },
                ]}
              />
            </DataBoundary>
          ) : (
            <Link to="/admin/attendance">{t('programme360.openAttendance')}</Link>
          )}
        </SubPanel>

        {/* TEAMS — ops/audit embed (from the RLS-shaped team list, filtered to this programme). */}
        {canEnrol && (
          <SubPanel tone="neutral">
            <Title level={5} style={{ marginTop: 0 }}>{t('programme360.teams')}</Title>
            <DataBoundary loading={teams.loading} error={teams.error} empty={progTeams.length === 0} emptySize="inline">
              <Table<TeamRow>
                rowKey="id" size="small" pagination={false} dataSource={progTeams}
                columns={[
                  { title: t('programme360.team'), dataIndex: 'name' },
                  { title: t('programme360.status'), dataIndex: 'status', render: (v: string) => <Tag>{v}</Tag> },
                  { title: t('programme360.members'), dataIndex: 'member_count', align: 'right' as const },
                ]}
              />
            </DataBoundary>
          </SubPanel>
        )}

        {/* LINKS ONWARD — config edits in the wizard; Financial Integrity is academy-wide (link, never embed). */}
        <SubPanel tone="neutral">
          <Space size="large" wrap>
            {has('configuration.manage') && <Link to="/admin/programmes">{t('programme360.openWizard')}</Link>}
            {(has('finance.record') || has('audit.read')) && <Link to="/admin/financial-integrity">{t('programme360.openFinance')}</Link>}
          </Space>
        </SubPanel>

        {!o && !overview.loading && <EmptyState size="inline" message={t('profile360.notFound')} />}
        <Paragraph type="secondary" style={{ fontSize: 12 }}>{t('programme360.hint')}</Paragraph>
      </Space>
    </div>
  );
}

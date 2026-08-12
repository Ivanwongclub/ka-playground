// R1-F2 — PROGRAMME 360 (/admin/programmes/{id}/overview). The CRM's programme noun-page: a header + the
// sections that a caller's OWN gated reads honestly serve. RLS is the authority — this composes, never
// widens. Gated ops ∨ audit ∨ config (server, in-controller). Section visibility is CAPABILITY-HONEST: a
// section whose read the caller cannot serve renders a LINK ONWARD, never a stub. Financial Integrity is
// academy-wide → always a link, never embedded.
import { useState } from 'react';
import { App, Button, Input, InputNumber, List, Modal, Space, Table, Tag, Typography } from 'antd';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { personName } from '../display/names';
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

// ── R2-ASSESS — the assessment lifecycle section (a section on Programme 360; ruling 2). The LIST reads
// the RLS-shaped /programmes/{id}/assessments; the write affordances (create/transition/grade/release)
// render ONLY for operations.manage holders and the server (assertOrganiser + the state machine + the
// enrolment precondition) is the authority — refusals surfaced. Scores are NEVER shown here from the list
// (it carries title/status only); grading is a separate ops modal. ────────────────────────────────────
interface Assessment { id: string; title: string; status: string; team_id: string | null }
interface AResult { student_id: number; score: number | null; graded_at: string | null }
interface AEnrol { student_id: number; status: string; student_name: string | null; programme_id: number }

// The single forward transition per state (the machine's happy path); pre-release states may also Cancel.
const NEXT: Record<string, string> = { draft: 'published', published: 'open', open: 'closed', closed: 'graded', graded: 'released' };
const CANCELLABLE = new Set(['draft', 'published', 'open', 'closed']);
const GRADEABLE = new Set(['closed', 'graded']); // mirrors AssessmentService::grade

function GradeModal({ assessment, programmeId, onClose }: { assessment: Assessment; programmeId: string; onClose: () => void }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const enrolments = useResource<{ data: AEnrol[] }>('/api/enrolments');
  const results = useResource<{ results: AResult[] }>(`/api/admin/assessments/${assessment.id}/results`);
  const [scores, setScores] = useState<Record<number, number | null>>({});
  // Live-enrolled students of THIS programme only — the server's own grade precondition, mirrored so the UI
  // never offers an ungradeable student (the server still 422s if state drifts; the refusal is surfaced).
  const students = (enrolments.data?.data ?? []).filter((e) => e.programme_id === Number(programmeId) && (e.status === 'confirmed' || e.status === 'active'));
  const existing = (id: number) => results.data?.results.find((r) => r.student_id === id)?.score ?? null;
  const save = async (studentId: number) => {
    const score = scores[studentId];
    if (score === null || score === undefined) return;
    const r = await mutate(`/api/admin/assessments/${assessment.id}/grade`, { student_id: studentId, score });
    if (r.ok) { void message.success(t('assess.scored')); results.reload(); }
    else void message.error(r.message ?? t('mutate.failed')); // per-student refusal surfaced individually
  };
  return (
    <Modal open title={t('assess.gradeTitle', { title: assessment.title })} footer={null} onCancel={onClose} width={520}>
      <DataBoundary loading={enrolments.loading} error={enrolments.error} empty={students.length === 0} emptySize="inline">
        <List<AEnrol>
          dataSource={students}
          renderItem={(e) => (
            <List.Item
              key={e.student_id}
              actions={[
                <InputNumber key="n" min={0} precision={0} style={{ width: 90 }} value={scores[e.student_id] ?? existing(e.student_id) ?? undefined} onChange={(v) => setScores((s) => ({ ...s, [e.student_id]: v as number }))} />,
                <Button key="s" size="small" type="primary" onClick={() => void save(e.student_id)}>{t('assess.save')}</Button>,
              ]}
            >
              <List.Item.Meta title={personName(e.student_name)} description={<Typography.Text type="secondary">{existing(e.student_id) !== null ? t('assess.current', { score: existing(e.student_id) }) : t('assess.ungraded')}</Typography.Text>} />
            </List.Item>
          )}
        />
      </DataBoundary>
    </Modal>
  );
}

function AssessmentsSection({ programmeId, canManage }: { programmeId: string; canManage: boolean }) {
  const { t } = useTranslation();
  const { modal, message } = App.useApp();
  const list = useResource<{ data: Assessment[] }>(`/api/programmes/${programmeId}/assessments`);
  const [creating, setCreating] = useState(false);
  const [title, setTitle] = useState('');
  const [gradeFor, setGradeFor] = useState<Assessment | null>(null);

  const surface = (r: { ok: boolean; message?: string }) => {
    if (r.ok) { void message.success(t('mutate.success')); list.reload(); }
    else void message.error(r.message ?? t('mutate.failed'));
  };
  const create = async () => { const r = await mutate(`/api/admin/programmes/${programmeId}/assessments`, { title: title.trim() }); setCreating(false); setTitle(''); surface(r); };
  const advance = (a: Assessment) => {
    const to = NEXT[a.status];
    if (!to) return;
    const release = to === 'released';
    modal.confirm({
      title: t(`assess.advanceTitle`, { action: t(`assess.action.${to}`), title: a.title }),
      // Release states BOTH consequences (visible to students & guardians + cannot be undone —
      // TRANSITIONS['released'] = [] verified in the machine); other steps are plain workflow.
      content: release ? t('assess.releaseBody') : t('assess.advanceBody'),
      okText: t(`assess.action.${to}`),
      okButtonProps: release ? { danger: true } : undefined,
      cancelText: t('common.cancel'),
      onOk: async () => surface(await mutate(`/api/admin/assessments/${a.id}/transition`, { to })),
    });
  };
  const cancel = (a: Assessment) =>
    modal.confirm({
      title: t('assess.cancelTitle', { title: a.title }),
      content: t('assess.cancelBody'),
      okText: t('assess.action.cancelled'), okButtonProps: { danger: true }, cancelText: t('common.cancel'),
      onOk: async () => surface(await mutate(`/api/admin/assessments/${a.id}/transition`, { to: 'cancelled' })),
    });

  return (
    <SubPanel tone="neutral">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Title level={5} style={{ margin: 0 }}>{t('assess.title')}</Title>
        {canManage && <Button size="small" type="primary" onClick={() => setCreating(true)}>{t('assess.new')}</Button>}
      </div>
      <DataBoundary loading={list.loading} error={list.error} empty={(list.data?.data.length ?? 0) === 0} emptySize="inline">
        <Table<Assessment>
          rowKey="id" size="small" pagination={false} dataSource={list.data?.data ?? []} style={{ marginTop: 8 }}
          columns={[
            { title: t('assess.name'), dataIndex: 'title' },
            { title: t('assess.status'), dataIndex: 'status', render: (s: string) => <Tag>{t(`assess.state.${s}`)}</Tag> },
            ...(canManage ? [{
              title: t('common.actions'), key: 'act',
              render: (_: unknown, a: Assessment) => (
                <Space>
                  {GRADEABLE.has(a.status) && <Button size="small" onClick={() => setGradeFor(a)}>{t('assess.grade')}</Button>}
                  {NEXT[a.status] && <Button size="small" type={NEXT[a.status] === 'released' ? 'primary' : 'default'} danger={NEXT[a.status] === 'released'} onClick={() => advance(a)}>{t(`assess.action.${NEXT[a.status]}`)}</Button>}
                  {CANCELLABLE.has(a.status) && <Button size="small" danger onClick={() => cancel(a)}>{t('assess.action.cancelled')}</Button>}
                </Space>
              ),
            }] : []),
          ]}
        />
      </DataBoundary>
      <Modal open={creating} title={t('assess.newTitle')} okText={t('assess.new')} cancelText={t('common.cancel')} okButtonProps={{ disabled: !title.trim() }} onOk={() => void create()} onCancel={() => { setCreating(false); setTitle(''); }}>
        <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t('assess.namePlaceholder')} />
      </Modal>
      {gradeFor && <GradeModal assessment={gradeFor} programmeId={programmeId} onClose={() => { setGradeFor(null); list.reload(); }} />}
    </SubPanel>
  );
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

        {/* R2-ASSESS — the assessment lifecycle (list read is RLS-shaped; write affordances are ops-only). */}
        <AssessmentsSection programmeId={String(id)} canManage={has('operations.manage')} />

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

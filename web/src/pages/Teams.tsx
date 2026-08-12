// S-UX3-3a STEP 2+3 — the ops-facing Team Formation view. A work queue of `submitted` teams (RLS-shaped
// GET /teams, B1 names), a per-team detail drawer with two tabs:
//  · Consent status (STEP 2) — per-member booleans/counts from /teams/{team}/consent-status (no
//    guardian identity); "X of N signed" is the PRIMARY signal, the coarse `blocker` a subordinate
//    hint; renders ONLY the STEP-1 allowlist keys, never a per-guardian breakdown.
//  · Roles & tenure (STEP 3) — /teams/{team}/roles (B3, member-readable, resolved within RLS): the
//    current holder per role + past (ended) tenures; assignRole records a rotation with a
//    tenure-change confirm. One active holder per role (DB-enforced); the read never shows two open.
// Team Formation confirm and assignRole are both SHOWN + ENABLED; the server is the authority and every refusal
// is rendered (S-UX3-1 error surface).
// R1-F2 (Part 3 ruling): the ops drawer below IS the Team 360 — it already composes consent + roles +
// stages, the full team read-set. No standalone /admin/teams/{id} page is built; the roster names here
// linkify to the Staff Student 360. (team_teacher_links remains an OPEN DECISION — no surface built.)
import { useState } from 'react';
import { Alert, App, Button, Drawer, List, Modal, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { StatusTag, humanise } from '../display/status';
import { programmeName, personName } from '../display/names';
import { formatHkt } from '../display/date';
import { ReasonModal } from '../components/ReasonModal';
// DS2 (restyle rollout M4 — money tier; last card). ALLOWED adopter (import-guard). Appearance only:
// the two titled section cards → SubPanel framing; Team Formation confirm/assign/resolution logic is byte-identical.
import { SubPanel, EmptyState } from '@/ds2';

const { Title, Paragraph, Text } = Typography;

interface TeamRow {
  id: string;
  programme_id: number;
  name: string;
  status: string;
  created_by_name: string | null;
  member_count: number;
  programme_name_en?: string | null;
  programme_name_tc?: string | null;
  programme_name_sc?: string | null;
  category_name_en?: string | null;
  category_name_tc?: string | null;
  category_name_sc?: string | null;
}

interface ConsentMember {
  student_id: number;
  student_name: string | null;
  satisfied: boolean;
  signed_count: number;
  guardian_count: number;
  blocker: string | null;
}

interface ConsentStatus {
  team_id: string;
  mode: 'any-one' | 'requires_all';
  all_satisfied: boolean;
  blocking_count: number;
  members: ConsentMember[];
}

interface TenureHolder {
  student_id: number;
  student_name: string | null;
  started_at: string;
  ended_at?: string | null;
  ended_reason?: string | null;
}

interface RoleRow {
  role_id: string;
  name_en: string;
  name_tc: string;
  name_sc: string;
  mandatory: boolean;
  current: TenureHolder | null;
  past: TenureHolder[];
}

interface TeamMemberRow {
  enrolment_id: string;
  student_id: number;
  student_name: string | null;
}

interface RolesData {
  team_id: string;
  roles: RoleRow[];
  members: TeamMemberRow[];
}

/** Localised name from a *_en/_tc/_sc triple; falls back across languages, then —. */
function triName(en?: string | null, tc?: string | null, sc?: string | null, locale?: KaLocale): string {
  const byLocale = locale === 'zh-TC' ? tc : locale === 'zh-SC' ? sc : en;
  return byLocale ?? en ?? tc ?? sc ?? '—';
}

export function Teams() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const { data, loading, error, reload } = useResource<{ data: TeamRow[] }>('/api/teams');
  const [open, setOpen] = useState<TeamRow | null>(null);

  // Detail reads are fetched only while a team is open.
  const detail = useResource<ConsentStatus>(open ? `/api/teams/${open.id}/consent-status` : '');
  const roles = useResource<RolesData>(open ? `/api/teams/${open.id}/roles` : '');
  // R1-P360 (3a): the Activity Tracker gates — same member/guardian/ops wall; ops passes via opsAudit.
  const gates = useResource<{ stages: { stage: string; passed: boolean }[] }>(open ? `/api/teams/${open.id}/tracker` : '');
  // S-MENTOR-1 (ruling 1): the team's linked teachers (names-only elevation read).
  const teachers = useResource<{ teachers: { teacher_id: number; teacher_name: string | null }[] }>(open ? `/api/teams/${open.id}/teachers` : '');

  // assignRole modal state
  const [assign, setAssign] = useState<RoleRow | null>(null);
  const [pick, setPick] = useState<string | undefined>(undefined);

  const allTeams = data?.data ?? [];
  const queue = allTeams.filter((r) => r.status === 'submitted');
  // Programmes the caller can see (from the RLS-shaped team list) drive the resolution console's picker.
  const progOptions = Object.values(
    allTeams.reduce((a, r) => {
      a[r.programme_id] = { value: r.programme_id, label: programmeName(r, locale) };
      return a;
    }, {} as Record<number, { value: number; label: string }>),
  );

  const surfaceError = (r: MutateResult) =>
    void message.error(
      r.message ??
        (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')),
    );

  // Team Formation success closes the drawer + refreshes the queue.
  const surfaceConfirm = (r: MutateResult) => {
    if (r.ok) {
      void message.success(t('teams.confirmed'));
      setOpen(null);
      reload();
      return;
    }
    surfaceError(r);
  };

  // Team Formation confirm — enabled + advisory; the server FOR SHARE re-check is the gate.
  const confirm = (team: TeamRow, blocking: number) =>
    modal.confirm({
      title: t('teams.confirmTitle', { team: team.name }),
      content: (
        <Space direction="vertical" size="small">
          <Text>{t('teams.confirmBody', { n: team.member_count })}</Text>
          {blocking > 0 && <Text type="warning">{t('teams.confirmAdvisory', { m: blocking })}</Text>}
        </Space>
      ),
      okText: t('teams.confirmCta'),
      cancelText: t('common.cancel'),
      onOk: async () => surfaceConfirm(await mutate(`/api/teams/${team.id}/confirm`)),
    });

  // assignRole — records a tenure rotation; refusals (403 authority, 409 already-holds / wrong state,
  // 422 not-a-member / wrong programme) rendered; roles refresh on success (drawer stays open).
  const doAssign = async () => {
    if (!open || !assign || !pick) return;
    const r = await mutate(`/api/teams/${open.id}/roles`, { enrolment_id: pick, role_id: assign.role_id });
    if (r.ok) {
      void message.success(t('teams.roleAssigned'));
      setAssign(null);
      setPick(undefined);
      roles.reload();
    } else {
      surfaceError(r);
    }
  };

  // R1-P360 (3b): approve a pending Activity-Tracker gate. Consequence-stating confirm (the stage becomes
  // PERMANENTLY passed and feeds the team's progress). The server (OD-39 approver gate + FR012/Spec:210
  // preconditions) is the AUTHORITY — a refusal (403 not-an-approver, 422 precondition, 409 already-passed)
  // is rendered, never pre-hidden beyond this tab's ops gating. Wires the EXISTING endpoint; no new write.
  const approveStage = (stage: string) =>
    modal.confirm({
      title: t('teams.stageApproveTitle', { stage: t(`tracker.stage${stage}`) }),
      content: t('teams.stageApproveBody', { stage: t(`tracker.stage${stage}`) }),
      okText: t('teams.stageApprove'),
      cancelText: t('common.cancel'),
      onOk: async () => {
        if (!open) return;
        const r = await mutate(`/api/teams/${open.id}/gates/${stage}/approve`);
        if (r.ok) { void message.success(t('teams.stagePassedMsg')); gates.reload(); }
        else surfaceError(r);
      },
    });

  // S-MENTOR-1 (ruling 5): unlink a mentor — consequence-stating (they lose the config-gated team view).
  // Server authority (lobby school-admin ∨ academy ops) is the enforcement; refusals surfaced.
  const unlinkTeacher = (teacherId: number, name: string) =>
    modal.confirm({
      title: t('teams.unlinkTitle', { name }),
      content: t('teams.unlinkBody'),
      okText: t('teams.unlink'),
      cancelText: t('common.cancel'),
      okButtonProps: { danger: true },
      onOk: async () => {
        if (!open) return;
        const r = await mutate(`/api/admin/teams/${open.id}/teacher-unlink`, { teacher_id: teacherId });
        if (r.ok) { void message.success(t('teams.unlinked')); teachers.reload(); }
        else surfaceError(r);
      },
    });

  const members = detail.data?.members ?? [];
  const pickName = (id?: string) => personName(roles.data?.members.find((m) => m.enrolment_id === id)?.student_name);

  const consentTab = (
    <DataBoundary loading={detail.loading} error={detail.error}>
      <Space direction="vertical" size="middle" style={{ width: '100%' }}>
        <Space>
          <Text type="secondary">{t('teams.consentMode')}:</Text>
          <Tag>{t(`teams.mode.${detail.data?.mode ?? 'any-one'}`)}</Tag>
          {detail.data && detail.data.blocking_count > 0 ? (
            <Text type="warning">{t('teams.blockingCount', { m: detail.data.blocking_count })}</Text>
          ) : (
            <Text type="success">{t('teams.allSatisfied')}</Text>
          )}
        </Space>
        <Table<ConsentMember>
          rowKey="student_id"
          size="small"
          dataSource={members}
          pagination={false}
          columns={[
            // R1-F2: the drawer roster (this IS the Team 360) links member names → Staff Student 360.
            { title: t('teams.member'), dataIndex: 'student_name', render: (v: string | null, m) => <Link to={`/admin/students/${m.student_id}`}>{personName(v)}</Link> },
            {
              title: t('teams.guardiansSigned'), key: 'signed',
              render: (_, m) => <Text>{t('teams.signedOf', { signed: m.signed_count, total: m.guardian_count })}</Text>,
            },
            {
              title: t('common.status'), key: 'consent',
              render: (_, m) =>
                m.satisfied ? (
                  <Tag color="success">{t('teams.satisfied')}</Tag>
                ) : (
                  <Tag color="warning">{m.blocker ? t(`teams.blocker.${m.blocker}`) : t('teams.notSatisfied')}</Tag>
                ),
            },
          ]}
        />
      </Space>
    </DataBoundary>
  );

  const rolesTab = (
    <DataBoundary loading={roles.loading} error={roles.error} empty={(roles.data?.roles.length ?? 0) === 0}>
      <List<RoleRow>
        itemLayout="vertical"
        size="small"
        dataSource={roles.data?.roles ?? []}
        renderItem={(role) => (
          <List.Item
            key={role.role_id}
            actions={[
              <Button key="assign" size="small" onClick={() => { setAssign(role); setPick(undefined); }}>
                {t('teams.roleAssign')}
              </Button>,
            ]}
          >
            <List.Item.Meta
              title={
                <Space>
                  <span>{triName(role.name_en, role.name_tc, role.name_sc, locale)}</span>
                  {role.mandatory && <Tag color="gold">{t('teams.roleMandatory')}</Tag>}
                </Space>
              }
              description={
                role.current ? (
                  <Text strong>{t('teams.roleCurrent')}: <Link to={`/admin/students/${role.current.student_id}`}>{personName(role.current.student_name)}</Link></Text>
                ) : (
                  <Text type="secondary">{t('teams.roleVacant')}</Text>
                )
              }
            />
            {role.past.length > 0 && (
              <div style={{ marginTop: 4 }}>
                <Text type="secondary" style={{ fontSize: 12 }}>{t('teams.rolePast')}:</Text>
                {role.past.map((p, i) => (
                  <div key={i} style={{ fontSize: 12, opacity: 0.75 }}>
                    <Link to={`/admin/students/${p.student_id}`}>{personName(p.student_name)}</Link> · {formatHkt(p.started_at, locale)} → {formatHkt(p.ended_at, locale)}
                  </div>
                ))}
              </div>
            )}
          </List.Item>
        )}
      />
    </DataBoundary>
  );

  const stagesTab = (
    <DataBoundary loading={gates.loading} error={gates.error}>
      <List<{ stage: string; passed: boolean }>
        size="small"
        dataSource={gates.data?.stages ?? []}
        renderItem={(g) => (
          <List.Item
            key={g.stage}
            actions={[
              g.passed
                ? <Tag key="st" color="success">{t('teams.stagePassed')}</Tag>
                : <Button key="ap" size="small" type="primary" onClick={() => approveStage(g.stage)}>{t('teams.stageApprove')}</Button>,
            ]}
          >
            <List.Item.Meta
              title={t(`tracker.stage${g.stage}`)}
              description={<Text type="secondary">{g.passed ? t('teams.stagePassed') : t('teams.stagePending')}</Text>}
            />
          </List.Item>
        )}
      />
    </DataBoundary>
  );

  // S-MENTOR-1 (ruling 1): read-only linked-teacher list + the ruling-5 unlink action.
  const teachersTab = (
    <DataBoundary loading={teachers.loading} error={teachers.error} empty={(teachers.data?.teachers.length ?? 0) === 0}>
      <List<{ teacher_id: number; teacher_name: string | null }>
        size="small"
        dataSource={teachers.data?.teachers ?? []}
        renderItem={(tt) => (
          <List.Item
            key={tt.teacher_id}
            actions={[<Button key="ul" size="small" danger onClick={() => unlinkTeacher(tt.teacher_id, personName(tt.teacher_name))}>{t('teams.unlink')}</Button>]}
          >
            <List.Item.Meta title={personName(tt.teacher_name)} description={<Text type="secondary">{t('teams.mentorRole')}</Text>} />
          </List.Item>
        )}
      />
    </DataBoundary>
  );

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('teams.title')}</Title>
        <Paragraph type="secondary">{t('teams.subtitle')}</Paragraph>
      </div>

      <DataBoundary loading={loading} error={error} empty={queue.length === 0}>
        <SubPanel tone="neutral">
          <Title level={5} style={{ marginTop: 0 }}>{t('teams.queueTitle')}</Title>
          <Table<TeamRow>
            rowKey="id"
            size="small"
            dataSource={queue}
            pagination={false}
            columns={[
              { title: t('teams.name'), dataIndex: 'name' },
              { title: t('teams.programme'), key: 'programme', render: (_, r) => programmeName(r, locale) },
              { title: t('teams.category'), key: 'category', render: (_, r) => triName(r.category_name_en, r.category_name_tc, r.category_name_sc, locale) },
              { title: t('teams.createdBy'), dataIndex: 'created_by_name', render: (v: string | null) => personName(v) },
              { title: t('teams.members'), dataIndex: 'member_count', align: 'right' as const },
              { title: t('common.status'), dataIndex: 'status', render: (s: string) => <StatusTag domain="teamStatus" value={s} /> },
              {
                title: t('common.actions'), key: 'act',
                render: (_, r) => <Button size="small" onClick={() => setOpen(r)}>{t('teams.review')}</Button>,
              },
            ]}
          />
        </SubPanel>
      </DataBoundary>

      {/* S-UX3-3a STEP 4 — below-min / matching + resolution (programme-scoped, academy-operations). */}
      <ResolutionConsole programmes={progOptions} teams={allTeams} />

      <Drawer
        width={560}
        open={open !== null}
        onClose={() => setOpen(null)}
        title={open ? t('teams.detailTitle', { team: open.name }) : ''}
        extra={
          open && (
            <Button type="primary" onClick={() => confirm(open, detail.data?.blocking_count ?? 0)}>
              {t('teams.confirmCta')}
            </Button>
          )
        }
      >
        {open && (
          <Tabs
            items={[
              { key: 'consent', label: t('teams.tabConsent'), children: consentTab },
              { key: 'roles', label: t('teams.rolesTitle'), children: rolesTab },
              { key: 'stages', label: t('teams.tabStages'), children: stagesTab },
              { key: 'teachers', label: t('teams.tabTeachers'), children: teachersTab },
            ]}
          />
        )}
      </Drawer>

      {/* assignRole — tenure-change confirm: a controlled member picker + consequence copy. The server
          is authoritative; refusals are rendered. (A plain Modal, not modal.confirm, so the Select is
          controllable.) */}
      <Modal
        open={assign !== null}
        title={assign ? t('teams.roleAssignTitle', { role: triName(assign.name_en, assign.name_tc, assign.name_sc, locale) }) : ''}
        okText={t('teams.roleAssignCta')}
        cancelText={t('common.cancel')}
        okButtonProps={{ disabled: !pick }}
        onOk={doAssign}
        onCancel={() => { setAssign(null); setPick(undefined); }}
      >
        {assign && (
          <>
            <Select
              style={{ width: '100%' }}
              placeholder={t('teams.rolePickMember')}
              value={pick}
              onChange={setPick}
              options={(roles.data?.members ?? []).map((m) => ({ value: m.enrolment_id, label: personName(m.student_name) }))}
            />
            {pick && (
              <Alert
                type="warning"
                showIcon
                style={{ marginTop: 12 }}
                message={
                  assign.current
                    ? t('teams.roleAssignBody', {
                        role: triName(assign.name_en, assign.name_tc, assign.name_sc, locale),
                        name: pickName(pick),
                        current: personName(assign.current.student_name),
                      })
                    : t('teams.roleAssignBodyNew', {
                        role: triName(assign.name_en, assign.name_tc, assign.name_sc, locale),
                        name: pickName(pick),
                      })
                }
              />
            )}
          </>
        )}
      </Modal>
    </Space>
  );
}

// ── S-UX3-3a STEP 4 — below-min / matching + resolution ─────────────────────────────────────────────
// Reads: B4 (matching, additive student names + min) + B5 (capacity report, additive approver/student/
// waived_by names) — both RLS-shaped (no elevation); the exception ledger is academy-ops/audit only per
// team_exceptions RLS. Writes: the five OD-37/OD-62 terminal actions, each SHOWN + ENABLED with a
// consequence-stating confirm; the server (academy operations) is authoritative and every refusal is
// rendered (403 authority, 409 wrong-state, 422 precondition). The RAISE stays system-automatic — no UI.
interface MatchTeam { team_id: string; name: string; member_count: number }
interface UnplacedRow { id: string; student_id: number; student_name: string | null }
interface ParkedRow { id: string; enrolment_id: string; student_name: string | null; backstop_at: string | null }
interface MatchingData { min_team_size: number | null; under_strength_teams: MatchTeam[]; unplaced_students: UnplacedRow[]; parked: ParkedRow[] }
interface ExceptionRow { id: string; type: string; status: string; team_id: string | null; enrolment_id: string | null; student_name: string | null; days_to_backstop: number | null }
interface WaiverRow { team_id: string; waiver_reason: string; waived_by_name: string | null; waived_at: string | null }
// confirm_log (approver names, B5) is audit-gated (audit_events RLS); the ACTIONABLE confirmed-team
// list is driven instead by the RLS-shaped GET /teams (not audit-gated) so a pure-ops admin — the OD-37
// authority — can see and resolve teams without needing audit.read.
interface CapacityReport { exception_ledger: ExceptionRow[]; waiver_register: WaiverRow[] }

function ResolutionConsole({ programmes, teams }: { programmes: { value: number; label: string }[]; teams: TeamRow[] }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const [pid, setPid] = useState<number | undefined>(undefined);
  const matching = useResource<MatchingData>(pid ? `/api/admin/programmes/${pid}/matching` : '');
  const report = useResource<CapacityReport>(pid ? `/api/admin/programmes/${pid}/team-capacity-report` : '');
  const [assignTeam, setAssignTeam] = useState<string | null>(null);
  const [assignPick, setAssignPick] = useState<string | undefined>(undefined);
  const [waiveTeam, setWaiveTeam] = useState<string | null>(null);

  const refresh = () => { matching.reload(); report.reload(); };
  const surface = (r: MutateResult) => {
    if (r.ok) { void message.success(t('teams.resDone')); refresh(); return; }
    void message.error(r.message ?? (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')));
  };

  const dissolve = (teamId: string) =>
    modal.confirm({
      title: t('teams.resDissolveTitle'), content: t('teams.resDissolveBody'),
      okText: t('teams.resDissolve'), cancelText: t('common.cancel'), okButtonProps: { danger: true },
      onOk: async () => surface(await mutate(`/api/admin/teams/${teamId}/dissolve`)),
    });
  const grace = (enrolmentId: string, teamId: string) =>
    modal.confirm({
      title: t('teams.resGraceTitle'), content: t('teams.resGraceBody'),
      okText: t('teams.resExtendGrace'), cancelText: t('common.cancel'),
      onOk: async () => surface(await mutate(`/api/admin/teams/${teamId}/extend-grace`, { enrolment_id: enrolmentId })),
    });
  const schoolLeave = (enrolmentId: string) =>
    modal.confirm({
      title: t('teams.resLeaveTitle'), content: t('teams.resLeaveBody'),
      okText: t('teams.resSchoolLeave'), cancelText: t('common.cancel'),
      onOk: async () => surface(await mutate(`/api/admin/team-members/${enrolmentId}/school-leave`)),
    });
  const doAssign = async () => {
    if (!assignTeam || !assignPick) return;
    const r = await mutate(`/api/admin/teams/${assignTeam}/assign`, { enrolment_id: assignPick });
    setAssignTeam(null); setAssignPick(undefined); surface(r);
  };
  const doWaive = async (reason: string) => {
    const team = waiveTeam; setWaiveTeam(null);
    if (team) surface(await mutate(`/api/admin/teams/${team}/waive`, { reason }));
  };

  const m = matching.data; const rep = report.data; const min = m?.min_team_size ?? null;
  const teamName = (id: string) => teams.find((x) => x.id === id)?.name ?? id.slice(0, 8);
  const confirmed = teams.filter((x) => x.programme_id === pid && x.status === 'confirmed');

  return (
    <SubPanel tone="neutral">
      <Title level={5} style={{ marginTop: 0 }}>{t('teams.resTitle')}</Title>
      <Space direction="vertical" size="middle" style={{ width: '100%' }}>
        <Select
          style={{ maxWidth: 380 }}
          placeholder={t('teams.resPickProgramme')}
          value={pid}
          onChange={(v) => setPid(v)}
          options={programmes}
        />
        {pid && (
          <DataBoundary loading={matching.loading || report.loading} error={matching.error || report.error}>
            <Space direction="vertical" size="middle" style={{ width: '100%' }}>
              <div>
                <Text strong>{t('teams.resUnderStrength')}</Text>
                <Table<MatchTeam>
                  rowKey="team_id" size="small" pagination={false} dataSource={m?.under_strength_teams ?? []}
                  locale={{ emptyText: <EmptyState size="inline" message={t('teams.resNone')} /> }}
                  columns={[
                    { title: t('teams.name'), dataIndex: 'name' },
                    { title: t('teams.resMemberCount'), key: 'mc', render: (_, r) => `${r.member_count}${min !== null ? ` / ${t('teams.resMin')} ${min}` : ''}` },
                  ]}
                />
              </div>

              <div>
                <Text strong>{t('teams.resUnplaced')}</Text>
                <Table<UnplacedRow>
                  rowKey="id" size="small" pagination={false} dataSource={m?.unplaced_students ?? []}
                  locale={{ emptyText: <EmptyState size="inline" message={t('teams.resNone')} /> }}
                  columns={[{ title: t('teams.member'), dataIndex: 'student_name', render: (v: string | null) => personName(v) }]}
                />
              </div>

              <div>
                <Text strong>{t('teams.resParked')}</Text>
                <Table<ParkedRow>
                  rowKey="id" size="small" pagination={false} dataSource={m?.parked ?? []}
                  locale={{ emptyText: <EmptyState size="inline" message={t('teams.resNone')} /> }}
                  columns={[
                    { title: t('teams.member'), dataIndex: 'student_name', render: (v: string | null) => personName(v) },
                    {
                      title: t('teams.resBackstop'), key: 'bs',
                      render: (_, r) => {
                        if (!r.backstop_at) return '—';
                        const days = Math.ceil((new Date(r.backstop_at).getTime() - Date.now()) / 86400000);
                        return <Tag color={days <= 0 ? 'error' : days <= 14 ? 'warning' : 'default'}>{t('teams.resDays', { n: days })}</Tag>;
                      },
                    },
                  ]}
                />
              </div>

              <div>
                <Text strong>{t('teams.resConfirmedTeams')}</Text>
                <Table<TeamRow>
                  rowKey="id" size="small" pagination={false} dataSource={confirmed}
                  locale={{ emptyText: <EmptyState size="inline" message={t('teams.resNone')} /> }}
                  columns={[
                    { title: t('teams.name'), dataIndex: 'name' },
                    { title: t('teams.resMemberCount'), key: 'mc', render: (_, r) => `${r.member_count}${min !== null ? ` / ${t('teams.resMin')} ${min}` : ''}` },
                    {
                      title: t('common.actions'), key: 'act',
                      render: (_, r) => (
                        <Space>
                          <Button size="small" onClick={() => { setAssignTeam(r.id); setAssignPick(undefined); }}>{t('teams.resAssign')}</Button>
                          <Button size="small" onClick={() => setWaiveTeam(r.id)}>{t('teams.resWaive')}</Button>
                          <Button size="small" danger onClick={() => dissolve(r.id)}>{t('teams.resDissolve')}</Button>
                        </Space>
                      ),
                    },
                  ]}
                />
              </div>

              <div>
                <Text strong>{t('teams.resExceptions')}</Text>
                <Table<ExceptionRow>
                  rowKey="id" size="small" pagination={false} dataSource={rep?.exception_ledger ?? []}
                  locale={{ emptyText: <EmptyState size="inline" message={t('teams.resNone')} /> }}
                  columns={[
                    { title: t('teams.resType'), dataIndex: 'type', render: (v: string) => humanise(v) },
                    { title: t('teams.resStatus'), dataIndex: 'status', render: (v: string) => humanise(v) },
                    { title: t('teams.member'), dataIndex: 'student_name', render: (v: string | null) => personName(v) },
                    { title: t('teams.resBackstop'), dataIndex: 'days_to_backstop', render: (v: number | null) => (v === null ? '—' : t('teams.resDays', { n: v })) },
                    {
                      title: t('common.actions'), key: 'act',
                      render: (_, r) =>
                        r.enrolment_id ? (
                          <Space>
                            <Button size="small" onClick={() => grace(r.enrolment_id!, r.team_id ?? '')}>{t('teams.resExtendGrace')}</Button>
                            <Button size="small" onClick={() => schoolLeave(r.enrolment_id!)}>{t('teams.resSchoolLeave')}</Button>
                          </Space>
                        ) : null,
                    },
                  ]}
                />
              </div>

              <div>
                <Text strong>{t('teams.resWaivers')}</Text>
                <Table<WaiverRow>
                  rowKey="team_id" size="small" pagination={false} dataSource={rep?.waiver_register ?? []}
                  locale={{ emptyText: <EmptyState size="inline" message={t('teams.resNone')} /> }}
                  columns={[
                    { title: t('teams.name'), key: 'nm', render: (_, r) => teamName(r.team_id) },
                    { title: t('teams.resReason'), dataIndex: 'waiver_reason' },
                    { title: t('teams.resApprover'), dataIndex: 'waived_by_name', render: (v: string | null) => personName(v) },
                    { title: t('common.when'), key: 'when', render: (_, r) => (r.waived_at ? formatHkt(r.waived_at, locale) : '—') },
                  ]}
                />
              </div>
            </Space>
          </DataBoundary>
        )}
      </Space>

      <ReasonModal
        open={waiveTeam !== null}
        title={t('teams.resWaiveTitle')}
        consequence={t('teams.resWaiveBody')}
        okText={t('teams.resWaive')}
        onOk={doWaive}
        onCancel={() => setWaiveTeam(null)}
      />

      <Modal
        open={assignTeam !== null}
        title={t('teams.resAssignTitle')}
        okText={t('teams.resAssign')}
        cancelText={t('common.cancel')}
        okButtonProps={{ disabled: !assignPick }}
        onOk={doAssign}
        onCancel={() => { setAssignTeam(null); setAssignPick(undefined); }}
      >
        <Select
          style={{ width: '100%' }}
          placeholder={t('teams.resUnplaced')}
          value={assignPick}
          onChange={setAssignPick}
          options={(m?.unplaced_students ?? []).map((u) => ({ value: u.id, label: personName(u.student_name) }))}
        />
        {assignPick && (
          <Alert
            type="warning" showIcon style={{ marginTop: 12 }}
            message={t('teams.resAssignBody', { name: personName(m?.unplaced_students.find((u) => u.id === assignPick)?.student_name) })}
          />
        )}
      </Modal>
    </SubPanel>
  );
}

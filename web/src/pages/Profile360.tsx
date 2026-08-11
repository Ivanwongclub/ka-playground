// R1-P360 — MY PROFILE / STUDENT 360. ONE composition (Profile360), consumed two ways this card: the
// student SELF-VIEW (/my/profile) and the guardian CHILD-VIEW (/my/children/:studentId). It renders NOTHING
// a caller's own reads don't already return — RLS is the authority; this component only COMPOSES existing
// reads (enrolments · teams+roster · tracker · roles), never widens. Built to be reused by the STAFF
// Student-360 (R1-F2) by passing a different studentId — the reads themselves gate per consumer.
//
// Wall (verified R1-P360): teams_read + stage_gates_read admit via tm.student_id = ANY(app.student_ids) —
// a guardian's student_ids ARE their children — and tenures_read admits "the holder's active guardian";
// ops passes all three via opsAudit. So the same composition serves self, guardian, and (later) staff.
import { useEffect, useState } from 'react';
import { Collapse, Skeleton, Space, Tag, Typography } from 'antd';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { authFetch } from '../auth/session';
import { useResource, DataBoundary } from '../api/useResource';
import { personName, programmeName } from '../display/names';
import { formatHkt } from '../display/date';
import { StatusTag } from '../display/status';
import { SubPanel, EmptyState, WizardRail } from '@/ds2';
import type { StepState } from '@/ds2';

const { Title, Text, Paragraph } = Typography;

interface EnrolRow { id: string; student_id: number; status: string; student_name: string | null; programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null }
interface TeamRow { id: string; name: string; status: string; member_count: number; programme_name_en?: string | null; programme_name_tc?: string | null; programme_name_sc?: string | null }
interface Member { student_id: number; student_name: string | null }
interface Tenure { student_id: number; student_name: string | null; started_at: string; ended_at?: string | null; ended_reason?: string | null }
interface RoleRow { role_id: string; name_en: string; name_tc: string; name_sc: string; mandatory: boolean; current: Tenure | null; past: Tenure[] }

// The enrolment journey (same order as Enrolments' active lens) — rendered DISPLAY-ONLY here (no onStep):
// the clickable journey stays on /enrolments. Terminal-bad states show their tag instead of a rail.
const JOURNEY = ['submitted', 'pending_consent', 'in_pool', 'teamed', 'confirmed', 'active', 'completed'];
const TERMINAL_BAD = ['withdrawn', 'released'];

function initials(name: string): string {
  const parts = name.split(/\s+/).filter(Boolean);
  return (parts.length >= 2 ? parts[0][0] + parts[1][0] : name.slice(0, 2)).toUpperCase();
}

function roleName(r: RoleRow, locale: KaLocale): string {
  return (locale === 'zh-TC' ? r.name_tc : locale === 'zh-SC' ? r.name_sc : r.name_en) || r.name_en;
}

function EnrolmentJourney({ status }: { status: string }) {
  const { t } = useTranslation();
  if (TERMINAL_BAD.includes(status)) return <StatusTag domain="enrolmentStatus" value={status} />;
  const cur = JOURNEY.indexOf(status);
  return (
    <WizardRail
      phases={[{
        title: t('enrol.journeyTitle'),
        steps: JOURNEY.map((s, i): { label: string; state: StepState } => ({
          label: t(`enrol.status.${s}`),
          state: i < cur ? 'done' : i === cur ? 'current' : 'todo',
        })),
      }]}
    />
  );
}

/** The composition. `studentId` selects the subject; `displayName` overrides the name shown (the self-view
 *  passes it from /api/me; the child-view derives it from the enrolment rows). */
export function Profile360({ studentId, displayName }: { studentId: number; displayName?: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;

  const enrolments = useResource<{ data: EnrolRow[] }>('/api/enrolments');
  const teams = useResource<{ data: TeamRow[] }>('/api/teams');

  // Resolve WHICH team is this student's by roster-matching the guardian/member-readable rosters (bounded:
  // only teams with ≥1 active member are probed; a student is in ≤1 team). No widen — /teams/{id}/members
  // is the member-or-guardian-walled read. Teamless → the section renders its EmptyState, not an error.
  const [team, setTeam] = useState<TeamRow | null>(null);
  const [teamResolved, setTeamResolved] = useState(false);
  useEffect(() => {
    if (!teams.data) return;
    const candidates = teams.data.data.filter((tm) => tm.member_count >= 1);
    let alive = true;
    (async () => {
      let found: TeamRow | null = null;
      for (const cand of candidates) {
        try {
          const r = await authFetch(`/api/teams/${cand.id}/members`);
          if (!r.ok) continue;
          const body = (await r.json()) as { members: Member[] | null };
          if (Array.isArray(body.members) && body.members.some((m) => m.student_id === studentId)) { found = cand; break; }
        } catch { /* skip a roster we cannot read — never an error surface */ }
      }
      if (alive) { setTeam(found); setTeamResolved(true); }
    })();
    return () => { alive = false; };
  }, [teams.data, studentId]);

  const tracker = useResource<{ stages: { stage: string; passed: boolean }[] }>(team ? `/api/teams/${team.id}/tracker` : null);
  const roles = useResource<{ roles: RoleRow[] }>(team ? `/api/teams/${team.id}/roles` : null);

  const myEnrolments = (enrolments.data?.data ?? []).filter((e) => e.student_id === studentId);
  const name = displayName ?? myEnrolments[0]?.student_name ?? '';

  // This student's own tenures (current + past), flattened from the per-role read.
  const myRoles: { role: RoleRow; tenure: Tenure; current: boolean }[] = [];
  for (const role of roles.data?.roles ?? []) {
    if (role.current && role.current.student_id === studentId) myRoles.push({ role, tenure: role.current, current: true });
    for (const p of role.past) if (p.student_id === studentId) myRoles.push({ role, tenure: p, current: false });
  }

  return (
    <div style={{ maxWidth: 900 }} data-density="product">
      {/* IDENTITY HEADER — initials + display name. No school: /api/me carries none and no student-own read
          does, so we do NOT widen for it (R1-P360 flag). */}
      <SubPanel tone="neutral">
        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <div style={{ width: 56, height: 56, borderRadius: '50%', background: 'linear-gradient(135deg,#2c2338,#3a2f4a)', display: 'grid', placeItems: 'center', fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 20, color: 'var(--ka-gold)', border: '1px solid var(--ka-border)', flex: '0 0 auto' }}>
            {initials(personName(name))}
          </div>
          <div>
            <Title level={3} style={{ margin: 0 }}>{personName(name)}</Title>
            <Text type="secondary">{t('profile360.subtitle')}</Text>
          </div>
        </div>
      </SubPanel>

      {/* PROGRAMME RECORD — every enrolment INCLUDING terminal states, each expandable to its journey. */}
      <div style={{ marginTop: 'var(--ka-zone-gap)' }}>
        <Title level={5} style={{ marginBottom: 8 }}>{t('profile360.programmeRecord')}</Title>
        <DataBoundary loading={enrolments.loading} error={enrolments.error} empty={myEnrolments.length === 0} emptySize="inline">
          <Collapse
            items={myEnrolments.map((e) => ({
              key: e.id,
              label: (
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                  {programmeName(e, locale)}
                  <StatusTag domain="enrolmentStatus" value={e.status} />
                </span>
              ),
              children: <EnrolmentJourney status={e.status} />,
            }))}
          />
        </DataBoundary>
      </div>

      {/* TEAM & ROLES — current team + status, the Activity Tracker rail (display-only), role history. */}
      <div style={{ marginTop: 'var(--ka-zone-gap)' }}>
        <Title level={5} style={{ marginBottom: 8 }}>{t('profile360.teamRoles')}</Title>
        {!teamResolved || teams.loading ? (
          <Skeleton active paragraph={{ rows: 3 }} />
        ) : !team ? (
          <SubPanel tone="neutral"><EmptyState size="inline" message={t('profile360.noTeam')} /></SubPanel>
        ) : (
          <SubPanel tone="neutral">
            <Space direction="vertical" size="middle" style={{ width: '100%' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 10 }}>
                <Text strong>{team.name}</Text>
                <StatusTag domain="teamStatus" value={team.status} />
              </span>

              <DataBoundary loading={tracker.loading} error={tracker.error}>
                <WizardRail
                  phases={[{
                    title: t('tracker.title'),
                    steps: (tracker.data?.stages ?? []).map((g): { label: string; state: StepState } => ({
                      label: t(`tracker.stage${g.stage}`),
                      state: g.passed ? 'done' : 'todo',
                    })),
                  }]}
                />
              </DataBoundary>

              <div>
                <Text type="secondary" style={{ fontSize: 12 }}>{t('profile360.roleHistory')}</Text>
                <DataBoundary loading={roles.loading} error={roles.error}>
                  {myRoles.length === 0 ? (
                    <div><Text type="secondary">{t('profile360.noRoles')}</Text></div>
                  ) : (
                    myRoles.map(({ role, tenure, current }, i) => (
                      <div key={i} style={{ fontSize: 13, marginTop: 4, display: 'inline-flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                        <Text strong>{roleName(role, locale)}</Text>
                        {current ? (
                          <Tag color="gold">{t('profile360.roleCurrent')}</Tag>
                        ) : (
                          <Text type="secondary">{formatHkt(tenure.started_at, locale)} → {tenure.ended_at ? formatHkt(tenure.ended_at, locale) : ''}</Text>
                        )}
                      </div>
                    ))
                  )}
                </DataBoundary>
              </div>
            </Space>
          </SubPanel>
        )}
      </div>
    </div>
  );
}

// ── Student SELF-VIEW: /my/profile ──────────────────────────────────────────────────────────────────
export function MyProfile() {
  const { identity } = useIdentity();
  if (!identity) return <Skeleton active paragraph={{ rows: 4 }} />;
  return <Profile360 studentId={identity.id} displayName={identity.name} />;
}

// ── Guardian CHILD-VIEW: /my/children/:studentId — the SAME Profile360 for the child, from the guardian's
// own (child-scoped) reads. The name is derived from the child's enrolment rows inside Profile360. ────
export function ChildProfile() {
  const { studentId } = useParams();
  const { t } = useTranslation();
  const id = Number(studentId);
  if (!Number.isFinite(id)) return <Paragraph type="secondary">{t('profile360.notFound')}</Paragraph>;
  return <Profile360 studentId={id} />;
}

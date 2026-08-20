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
import { TERMINAL_BAD } from '../display/enrolmentJourney'; // C5-CONSUME: one definition of the terminal states
import { SubPanel, EmptyState, WizardRail, RecordShell, RecordHeaderBand, GlanceCard, JourneyStepper } from '@/ds2';
import type { StepState, SegItem, GlanceRow } from '@/ds2';

const { Title, Text, Paragraph } = Typography;

interface EnrolRow { id: string; student_id: number; programme_id: number; status: string; student_name: string | null; programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null }
interface TeamRow { id: string; name: string; status: string; member_count: number; programme_name_en?: string | null; programme_name_tc?: string | null; programme_name_sc?: string | null }
interface Member { student_id: number; student_name: string | null }
interface Tenure { student_id: number; student_name: string | null; started_at: string; ended_at?: string | null; ended_reason?: string | null }
interface RoleRow { role_id: string; name_en: string; name_tc: string; name_sc: string; mandatory: boolean; current: Tenure | null; past: Tenure[] }

// The enrolment journey (same order as Enrolments' active lens) — rendered DISPLAY-ONLY here (no onStep):
// the clickable journey stays on /enrolments. Terminal-bad states show their tag instead of a rail.
const JOURNEY = ['submitted', 'pending_consent', 'in_pool', 'teamed', 'confirmed', 'active', 'completed'];

function initials(name: string): string {
  const parts = name.split(/\s+/).filter(Boolean);
  return (parts.length >= 2 ? parts[0][0] + parts[1][0] : name.slice(0, 2)).toUpperCase();
}

function roleName(r: RoleRow, locale: KaLocale): string {
  return (locale === 'zh-TC' ? r.name_tc : locale === 'zh-SC' ? r.name_sc : r.name_en) || r.name_en;
}

// R2-ASSESS (ruling 3) — released assessment results in the programme record (the record lens; the guardian
// child-view and staff-360 inherit this via the same component — the reuse contract). N+1 compose: the RLS
// list gives the programme's assessments; each RELEASED one's score is fetched from the EMBARGOED result read
// (returns null until Released / for non-family). Renders NOTHING when the student has no released result —
// no teasing empty section (for ALL three consumers).
function ReleasedResults({ studentId, programmeId }: { studentId: number; programmeId: number }) {
  const { t } = useTranslation();
  const assessments = useResource<{ data: { id: string; title: string; status: string }[] }>(`/api/programmes/${programmeId}/assessments`);
  const [results, setResults] = useState<{ title: string; score: number }[]>([]);
  useEffect(() => {
    const released = (assessments.data?.data ?? []).filter((a) => a.status === 'released');
    if (released.length === 0) { setResults([]); return; }
    let alive = true;
    (async () => {
      const out: { title: string; score: number }[] = [];
      for (const a of released) {
        try {
          const r = await authFetch(`/api/assessments/${a.id}/results/${studentId}`);
          if (!r.ok) continue;
          const body = (await r.json()) as { result: { score: number | null } | null };
          if (body.result && body.result.score !== null) out.push({ title: a.title, score: body.result.score });
        } catch { /* embargoed / unreadable → simply not shown */ }
      }
      if (alive) setResults(out);
    })();
    return () => { alive = false; };
  }, [assessments.data, studentId]);

  if (results.length === 0) return null; // honest: no released result → nothing rendered
  return (
    <div style={{ marginTop: 10 }}>
      <Text type="secondary" style={{ fontSize: 12 }}>{t('assess.results')}</Text>
      {results.map((r, i) => (
        <div key={i} style={{ fontSize: 13, marginTop: 2 }}><Text strong>{r.title}</Text>: {r.score}</div>
      ))}
    </div>
  );
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

// V3-STUDENT360 — the FAMILY (§C2) journey: the SAME 7-state display-only journey as EnrolmentJourney (identical
// JOURNEY / TERMINAL_BAD / indexOf logic), rendered via the P0-4-era JourneyStepper (bare — no tiles/whatNext)
// instead of WizardRail. NEW component: EnrolmentJourney (above) stays untouched for the admin branch. antd Steps
// `current={cur}` yields the same done(<cur)/current(===cur)/todo(>cur) semantics the WizardRail mapped by hand.
function EnrolmentJourneyStepper({ status, locale }: { status: string; locale: KaLocale }) {
  const { t } = useTranslation();
  if (TERMINAL_BAD.includes(status)) return <StatusTag domain="enrolmentStatus" value={status} />;
  const cur = JOURNEY.indexOf(status);
  return <JourneyStepper current={cur} locale={locale} steps={JOURNEY.map((s) => ({ title: t(`enrol.status.${s}`) }))} />;
}

/** The composition. `studentId` selects the subject; `displayName` overrides the name shown (the self-view
 *  passes it from /api/me; the child-view derives it from the enrolment rows). */
export function Profile360({ studentId, displayName, density = 'product' }: { studentId: number; displayName?: string; density?: 'product' | 'admin' }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;

  const enrolments = useResource<{ data: EnrolRow[] }>('/api/enrolments');
  const teams = useResource<{ data: TeamRow[] }>('/api/teams');

  // Resolve WHICH team is this student's by roster-matching the guardian/member-readable rosters (bounded:
  // only teams with ≥1 active member are probed; a student is in ≤1 team). No widen — /teams/{id}/members
  // is the member-or-guardian-walled read. Teamless → the section renders its EmptyState, not an error.
  // R1-F2 note: for STAFF (ops sees ALL teams) this iterates every team until it matches — accepted as a
  // known bound for phase 1; a future direct student→team read is the optimization path if team counts grow.
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

  // ── FAMILY grammar (§C2 · V3-STUDENT360) — RecordShell single-column: header + main, NO highlights/rail/
  // history (those are staff §C3, deferred to V3-STUDENT360-STAFF). The shared fetch/derivation block above is
  // UNCHANGED; only this product-density render is new. Enrolment GlanceCards are DISPLAY-ONLY (no drill):
  // Profile360 has no nav today and nav.tsx bars the student from /enrolments (R1-S2), so routing out would be a
  // regression disguised as adoption — the journey + released results render inline. Avatar dropped (§C1/§C2
  // headers carry none; it was decorative). ──
  if (density === 'product') {
    return (
      <div style={{ maxWidth: 900 }} data-density={density}>
        <RecordShell
          header={<RecordHeaderBand eyebrow={t('profile360.subtitle')} name={personName(name)} />}
          main={(
            <>
              <div>
                <Title level={5} style={{ marginBottom: 8 }}>{t('profile360.programmeRecord')}</Title>
                <DataBoundary loading={enrolments.loading} error={enrolments.error} empty={myEnrolments.length === 0} emptySize="inline">
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    {myEnrolments.map((e) => (
                      <div key={e.id} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <GlanceCard
                          imageFallback={<div />}
                          title={programmeName(e, locale)}
                          status={<StatusTag domain="enrolmentStatus" value={e.status} />}
                        />
                        <EnrolmentJourneyStepper status={e.status} locale={locale} />
                        <ReleasedResults studentId={studentId} programmeId={e.programme_id} />
                      </div>
                    ))}
                  </div>
                </DataBoundary>
              </div>

              <div>
                <Title level={5} style={{ marginBottom: 8 }}>{t('profile360.teamRoles')}</Title>
                {!teamResolved || teams.loading ? (
                  <Skeleton active paragraph={{ rows: 3 }} />
                ) : !team ? (
                  <SubPanel tone="neutral"><EmptyState size="inline" message={t('profile360.noTeam')} /></SubPanel>
                ) : (
                  <GlanceCard
                    imageFallback={<div />}
                    title={team.name}
                    status={<StatusTag domain="teamStatus" value={team.status} />}
                    // tracker → segbar: SAME truth the admin WizardRail maps (line: g.passed ? 'done' : 'todo') —
                    // completed stage = done segment, else todo. No 'current' re-interpretation.
                    segments={(tracker.data?.stages ?? []).map((g): SegItem => ({
                      label: t(`tracker.stage${g.stage}`),
                      state: g.passed ? 'done' : 'todo',
                    }))}
                    rows={
                      roles.loading
                        ? []
                        : myRoles.length === 0
                          ? [{ label: t('profile360.roleHistory'), value: { text: t('profile360.noRoles') } }]
                          : myRoles.map(({ role, tenure, current }): GlanceRow => ({
                              label: roleName(role, locale),
                              value: current
                                ? { tag: <Tag color="gold">{t('profile360.roleCurrent')}</Tag> }
                                : { text: `${formatHkt(tenure.started_at, locale)} → ${tenure.ended_at ? formatHkt(tenure.ended_at, locale) : ''}` },
                            }))
                    }
                  />
                )}
              </div>
            </>
          )}
        />
      </div>
    );
  }

  // ── ADMIN grammar (density==='admin') — the pre-V3 composition, UNCHANGED (deferred to V3-STUDENT360-STAFF). ──
  return (
    <div style={{ maxWidth: 900 }} data-density={density}>
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

      {/* TEAM & ROLES — current team + status, role history. CLEANUP-1 part 2: the Activity Tracker rail and
          the released-results block are DELETED from the staff surface (§C3) — the prototype's ops-stu carries
          neither; its team/role content is a tenures line. Deletion only, nothing rebuilt: the staff Student-360
          recomposition remains parked. Both still render on the FAMILY (§C2) surface, which is their home. */}
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

// ── R1-F2 — STAFF Student 360: /admin/students/:studentId. The SAME Profile360 (the R1-P360 reuse contract),
// at ADMIN density, composed from the staff caller's own reads. Gate operations.manage ∨ audit.read (super
// via *); both empirically admit every read. Entry is via linkified student names on staff surfaces — there
// is no student picker this card. Only the density differs from the guardian child-view. ────────────────
export function StaffStudent360() {
  const { studentId } = useParams();
  const { t } = useTranslation();
  const id = Number(studentId);
  if (!Number.isFinite(id)) return <Paragraph type="secondary">{t('profile360.notFound')}</Paragraph>;
  return <Profile360 studentId={id} density="admin" />;
}

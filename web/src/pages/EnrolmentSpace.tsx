// C1-SHELL / C4-TABS-1 / C5-CONSUME — the family SCOPED PROGRAMME SPACE (D-1: keyed on ENROLMENT_ID). C5-CONSUME
// spends the S-READ-1 reads: the space now reads GET /api/enrolments/{id} (detail) directly — the client-side
// list-filter interim is gone — and fills the Journey stat-tile row (Team from team_name, Next session from the
// now-scoped session read, Consent from consent-requests) and the Sessions tab (this enrolment's sessions via
// programme_id; book/cancel are student-only). The band image is the detail read's banner_url (the data:, hack
// is gone). Both personas reach it: student → /my/sessions, guardian → /my/students/{id}/sessions.
//
// C7-RESULTS fills the Results tab (embargo-coarsened assessment reads). C8-TEAM fills the Team tab: the existing
// roster/mentor/formation endpoints composed enrolment-scoped via the shared team module (see TeamPanel). STILL
// BLOCKED, each with a named blocker: the Tracker tab (needs the tracker read); stepper per-knot DATES
// (per-transition timestamps live only in audit_events); the tile .ev sub-line (JourneyTile has no sub slot + no
// signature-detail read); session LOCATION + MATERIALS (not in the session read). A 404 from the detail endpoint
// (out-of-scope) → NotFound (A1).
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { App, Button, Input, Select, Skeleton, Space, Tabs, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { authFetch } from '../auth/session';
import { mutate, type MutateResult } from '../api/mutate';
import { useIdentity } from '../auth/identity';
import { programmeName, personName } from '../display/names';
import { StatusTag } from '../display/status';
import { formatHkt } from '../display/date';
import { SEG, WHATNEXT, currentIndex, isTerminal } from '../display/enrolmentJourney';
// C8-TEAM — shared team-formation primitives (single definition; role renders plain, count-only wall).
import { tri, MemberRole, JoinableCount, memberInitials, type TeamRow, type Roster, type Lobby } from '../display/team';
import { ProgrammeBandHeader, EmptyState, JourneyStepper, SubPanel } from '@/ds2';
import { NotFound } from './NotFound';

interface Detail {
  id: string; programme_id: number; student_id: number; status: string;
  programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null;
  banner_url: string | null; team_id: string | null; team_name: string | null;
}
interface Session { id: string; title: string; starts_at: string; status: string; programme_id: number; booking_status: string | null }
interface ConsentReq { programme_id: number; student_id: number; status: string }

// spec C4 order — FIXED: Journey · Team · Sessions · Tracker · Results.
const TABS = ['journey', 'team', 'sessions', 'tracker', 'results'] as const;

// ── one session row — title + date + booking chip + (student, upcoming) book/cancel. Location + materials are
// omitted (not in the read, entitlement-iff). Book is the row's one gold (≤1 per row); cancel is quiet. ──
function SessionRow({ s, isStudent, locale, onAct }: { s: Session; isStudent: boolean; locale: KaLocale; onAct: (id: string, path: 'book' | 'cancel') => void }) {
  const { t } = useTranslation();
  const upcoming = new Date(s.starts_at).getTime() > Date.now() && !['cancelled', 'completed'].includes(s.status);
  const booked = s.booking_status === 'booked' || s.booking_status === 'waitlisted';
  const bookable = (s.booking_status == null || s.booking_status === 'cancelled') && (s.status === 'published' || s.status === 'full');
  return (
    <SubPanel tone="neutral">
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <Typography.Text strong style={{ display: 'block' }}>{s.title}</Typography.Text>
          <Typography.Text type="secondary">{formatHkt(s.starts_at, locale)}</Typography.Text>
        </div>
        <Space size="middle">
          {s.booking_status && <StatusTag domain="bookingStatus" value={s.booking_status} />}
          {isStudent && upcoming && bookable && <Button type="primary" size="small" className="ka-cta" onClick={() => onAct(s.id, 'book')}>{t('enrolSpace.sessions.book')}</Button>}
          {isStudent && upcoming && booked && <Button size="small" onClick={() => onAct(s.id, 'cancel')}>{t('enrolSpace.sessions.cancel')}</Button>}
        </Space>
      </div>
    </SubPanel>
  );
}

interface Assessment { id: string; title: string; status: string }

// ── C7-RESULTS — the Results tab (E1). Lists ALL the programme's assessments for this enrolment (released and
// not), both personas identically (the guardian's read entitlement mirrors the child's — assessments_read /
// assessment_results_read both carry a guardian roster arm).
//
// THE EMBARGO IS THE POINT. Two properties, both structural, not cosmetic:
//   1. COARSENING. The raw status (draft|published|open|closed|graded|released) is collapsed to a single
//      released? bit BEFORE it reaches any pill. A `graded`-but-unreleased assessment renders IDENTICALLY to a
//      `published` one — surfacing `graded` would tell the family the result EXISTS and is being withheld, which
//      is the embargo leaking one bit. The pill only ever sees 'pending' | 'released'.
//   2. NO SPECULATIVE PROBE. The score read fires ONLY for a released assessment, so no request pattern reveals a
//      pending assessment's state (a pending one is never probed). The RLS would return null anyway, but not
//      asking is the stronger guarantee.
// Cancelled assessments are EXCLUDED (they never release → a "not yet released" chip would be a false promise;
// the prototype's t-res has no cancelled state, so inventing one would be infidelity). No date and no denominator
// on the released card: `assessments.released_at` and a `max_score` column do not exist — both FLAGGED for a
// migration card, neither faked here (graded_at is the GRADER's timestamp — embargo-adjacent, not the family's).
function ResultsPanel({ programmeId, studentId }: { programmeId: number; studentId: number }) {
  // RLS list: title/status only — the score is NEVER here (it is embargoed in assessment_results_read).
  const list = useResource<{ data: Assessment[] }>(`/api/programmes/${programmeId}/assessments`);
  const listData = list.data?.data;
  const assessments = (listData ?? []).filter((a) => a.status !== 'cancelled');

  // Scores for RELEASED assessments only — one embargoed result read each (1 + R requests, R = released count).
  const [scores, setScores] = useState<Record<string, number>>({});
  useEffect(() => {
    const released = (listData ?? []).filter((a) => a.status === 'released');
    if (released.length === 0) { setScores({}); return; }
    let alive = true;
    void (async () => {
      const out: Record<string, number> = {};
      for (const a of released) {
        try {
          const r = await authFetch(`/api/assessments/${a.id}/results/${studentId}`);
          if (!r.ok) continue;
          const body = (await r.json()) as { result: { score: number | null } | null };
          if (body.result && body.result.score !== null) out[a.id] = body.result.score;
        } catch { /* embargoed / unreadable → simply no score, no leak */ }
      }
      if (alive) setScores(out);
    })();
    return () => { alive = false; };
  }, [listData, studentId]);

  if (list.loading) return <Skeleton active paragraph={{ rows: 2 }} />;
  if (assessments.length === 0) return <EmptyState message={null} />; // entitlement-iff, no placeholder rows

  return (
    <Space direction="vertical" size="middle" style={{ width: '100%' }}>
      {assessments.map((a) => {
        const released = a.status === 'released'; // the ONLY status bit that crosses to the view
        const score = scores[a.id];
        return (
          <SubPanel key={a.id} tone={released ? 'attested' : 'neutral'}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
              <Typography.Text strong>{a.title}</Typography.Text>
              <StatusTag domain="assessmentRelease" value={released ? 'released' : 'pending'} />
            </div>
            {released && score !== undefined && (
              // The panel's one VALUE-gold (ruling 2): gold here is a VALUE, not an action — do NOT apply the
              // ≤1-action-gold rule to it. There is no action on this surface. No "/ 100" (max unmodelled).
              <div style={{ fontSize: 34, color: 'var(--ka-gold)', fontWeight: 700, marginTop: 8 }}>{score}</div>
            )}
          </SubPanel>
        );
      })}
    </Space>
  );
}

// ── C8-TEAM — the Team tab (D-1, enrolment-scoped). Composes the SAME endpoints /my/team uses (GET /teams,
// /teams/{id}/members, /teams/{id}/teachers, /programmes/{id}/lobbies, create/join/submit), filtered to THIS
// enrolment's programme, through the shared team module (no reimplementation). Every WRITE is student-only
// (routes gate role:student), so create/join/submit render iff isStudent — the GUARDIAN gets the read-only
// mirror with NO actions.
//
// Deferrals, each with its class:
//   • "Join requests · you are Team Lead" (Accept/Decline) — DOMAIN-UNBUILT: no join-request table exists;
//     joins are direct inserts. Omitted, not faked.
//   • "Join with code" — DOMAIN-UNBUILT: invite codes (B-2/J-3, M-1-gated) unbuilt. Omitted.
//   • wall "Led by {name}" — MODEL-FORBIDS (D-7): users_read is own-row-only for a student, so a non-member
//     cannot read the organiser's name (created_by_name resolves NULL). The wall shows name + count only.
//   • wall "· Public/Private" + pitch blurb — MODEL-LACKS-THE-FIELD: teams has no visibility/description column.
// Submit is submitter-only and is ABSENT (not disabled) unless the viewer is the team's created_by — the
// GET /teams read already carries created_by, so this is a client-side gate with NO new read.
function TeamPanel({ programmeId, status, isStudent, viewerId }: { programmeId: number; status: string; isStudent: boolean; viewerId: number | undefined }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { message, modal } = App.useApp();
  // GET /teams — RLS-shaped: the viewer's own team (member_count>=1) + forming lobby-wall teams (count 0, student
  // persona only; a guardian's role does not match the teams_read lobbyWall arm, so their wall is naturally empty).
  const teams = useResource<{ data: TeamRow[] }>('/api/teams');
  const mine = (teams.data?.data ?? []).filter((x) => x.programme_id === programmeId);
  const myTeam = mine.find((x) => x.member_count >= 1) ?? null;
  const joinable = mine.filter((x) => x.member_count === 0 && x.status === 'forming');

  const surface = (r: MutateResult, okKey: string) => {
    if (r.ok) { void message.success(t(okKey)); teams.reload(); return; }
    void message.error(r.message ?? (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')));
  };
  const join = async (teamId: string) => surface(await mutate(`/api/teams/${teamId}/join`), 'studentTeam.joinedOk');
  const submit = (team: TeamRow) => modal.confirm({
    title: t('studentTeam.submitTitle', { team: team.name }),
    content: t('studentTeam.submitBody'),
    okText: t('studentTeam.submit'), cancelText: t('common.cancel'),
    onOk: async () => surface(await mutate(`/api/teams/${team.id}/submit`), 'studentTeam.submitted'),
  });

  if (teams.loading) return <Skeleton active paragraph={{ rows: 3 }} />;
  if (myTeam) return <TeamedView team={myTeam} isStudent={isStudent} viewerId={viewerId} locale={locale} onSubmit={submit} />;
  if (status === 'in_pool') return <InPoolView programmeId={programmeId} joinable={joinable} isStudent={isStudent} locale={locale} onJoin={join} onChange={() => teams.reload()} />;
  return <EmptyState message={null} />; // pre-pool (pending_consent/submitted) or terminal — nothing to form yet
}

function TeamedView({ team, isStudent, viewerId, locale, onSubmit }: { team: TeamRow; isStudent: boolean; viewerId: number | undefined; locale: KaLocale; onSubmit: (team: TeamRow) => void }) {
  const { t } = useTranslation();
  const roster = useResource<Roster>(`/api/teams/${team.id}/members`);
  const teachers = useResource<{ teachers: { teacher_id: number; teacher_name: string | null }[] }>(`/api/teams/${team.id}/teachers`);
  // Submit: submitter-only, ABSENT (not disabled) for a non-submitter. created_by rides the GET /teams read.
  const canSubmit = isStudent && team.status === 'forming' && viewerId !== undefined && team.created_by === viewerId;
  return (
    <Space direction="vertical" size="middle" style={{ width: '100%' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <Typography.Title level={5} style={{ margin: 0 }}>{team.name}</Typography.Title>
        <StatusTag domain="teamStatus" value={team.status} />
      </div>
      {/* Teammates — names + plain role; "(you)" on the viewer's own row (student persona only). Never contact. */}
      <div>
        <Typography.Text strong>{t('enrolSpace.team.teammates')}</Typography.Text>
        <DataBoundary loading={roster.loading} error={roster.error}>
          <Space direction="vertical" size={8} style={{ width: '100%', marginTop: 8 }}>
            {(roster.data?.members ?? []).map((m) => (
              <div key={m.student_id} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span style={{ width: 30, height: 30, borderRadius: '50%', display: 'grid', placeItems: 'center', background: 'var(--ka-muted)', border: '1px solid var(--ka-border)', fontSize: 12, fontWeight: 700, flex: '0 0 auto' }}>{memberInitials(m.student_name)}</span>
                <span style={{ flex: 1, minWidth: 0 }}>{personName(m.student_name)}{isStudent && m.student_id === viewerId ? ` ${t('enrolSpace.team.you')}` : ''}</span>
                <MemberRole role={m.role} locale={locale} />
              </div>
            ))}
          </Space>
        </DataBoundary>
      </div>
      {/* Mentor — plain text, no chip/link (A3). Only when the programme enables the mentor view. */}
      {(teachers.data?.teachers.length ?? 0) > 0 && (
        <div>
          <Typography.Text strong>{t('studentTeam.mentor')}</Typography.Text>
          <div style={{ marginTop: 4 }}><Typography.Text>{(teachers.data?.teachers ?? []).map((tt) => personName(tt.teacher_name)).join(', ')}</Typography.Text></div>
        </div>
      )}
      {canSubmit && <div><Button type="primary" className="ka-cta" onClick={() => onSubmit(team)}>{t('studentTeam.submit')}</Button></div>}
    </Space>
  );
}

function InPoolView({ programmeId, joinable, isStudent, locale, onJoin, onChange }: { programmeId: number; joinable: TeamRow[]; isStudent: boolean; locale: KaLocale; onJoin: (id: string) => void; onChange: () => void }) {
  const { t } = useTranslation();
  return (
    <Space direction="vertical" size="middle" style={{ width: '100%' }}>
      <div>
        <Typography.Text strong>{t('enrolSpace.team.noTeam')}</Typography.Text>
        <div><Typography.Text type="secondary">{t('enrolSpace.team.inPool')}</Typography.Text></div>
      </div>
      {isStudent && <CreateTeam programmeId={programmeId} locale={locale} onChange={onChange} />}
      {joinable.length > 0 && (
        <div>
          <Typography.Text strong>{t('enrolSpace.team.forming')}</Typography.Text>
          {/* Count-only wall (name + member count). NO organiser name (MODEL-FORBIDS, D-7). Join is student-only. */}
          <Space direction="vertical" size={8} style={{ width: '100%', marginTop: 8 }}>
            {joinable.map((team) => (
              <div key={team.id} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span style={{ flex: 1, minWidth: 0 }}>{team.name}</span>
                <JoinableCount teamId={team.id} />
                {isStudent && <Button size="small" onClick={() => onJoin(team.id)}>{t('studentTeam.join')}</Button>}
              </div>
            ))}
          </Space>
        </div>
      )}
    </Space>
  );
}

function CreateTeam({ programmeId, locale, onChange }: { programmeId: number; locale: KaLocale; onChange: () => void }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const lobbies = useResource<{ data: Lobby[] }>(`/api/programmes/${programmeId}/lobbies`);
  const [lobbyId, setLobbyId] = useState<string | undefined>(undefined);
  const [name, setName] = useState('');
  const eligibleLobbies = (lobbies.data?.data ?? []).filter((l) => l.eligible);
  const create = async () => {
    if (!lobbyId || name.trim().length < 2) return;
    const r = await mutate('/api/my/teams', { programme_id: programmeId, category_id: lobbyId, name: name.trim() });
    if (r.ok) { setName(''); setLobbyId(undefined); void message.success(t('studentTeam.createdOk')); onChange(); }
    else void message.error(r.message ?? (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')));
  };
  return (
    <div>
      <Typography.Text strong>{t('studentTeam.createTitle')}</Typography.Text>
      <DataBoundary loading={lobbies.loading} error={lobbies.error}>
        <Space direction="vertical" style={{ width: '100%', marginTop: 8 }}>
          <Select
            style={{ width: '100%', maxWidth: 360 }}
            placeholder={t('studentTeam.createLobby')}
            value={lobbyId}
            onChange={setLobbyId}
            options={eligibleLobbies.map((l) => ({
              value: l.id,
              label: (<span>{tri(l, locale)}{l.school_bound && <> <Tooltip title={t('studentTeam.schoolBoundHint')}>★</Tooltip></>}</span>),
            }))}
          />
          <Input style={{ maxWidth: 360 }} placeholder={t('studentTeam.createName')} value={name} onChange={(e) => setName(e.target.value)} maxLength={80} />
          {/* The in-pool action-gold (≤1 per state): Create. Join above is quiet. */}
          <Button type="primary" className="ka-cta" disabled={!lobbyId || name.trim().length < 2} onClick={() => void create()}>{t('studentTeam.createCta')}</Button>
        </Space>
      </DataBoundary>
    </div>
  );
}

export function EnrolmentSpace() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { message } = App.useApp();
  const { enrolmentId } = useParams();
  const { identity } = useIdentity();

  // C5-CONSUME: the space's OWN read is now the detail endpoint (S-READ-1) — not the list-filter interim.
  const detail = useResource<Detail>(`/api/enrolments/${enrolmentId}`);
  const d = detail.data;
  const isStudent = !!(d && identity && identity.id === d.student_id);
  // dependent reads (fire once the detail resolves student_id/programme_id): persona-split sessions + consent.
  const sessionsRes = useResource<{ sessions: Session[] }>(d ? (isStudent ? '/api/my/sessions' : `/api/my/students/${d.student_id}/sessions`) : null);
  const consentRes = useResource<{ data: ConsentReq[] }>(d ? '/api/consent-requests' : null);

  if (detail.loading) return <Skeleton active paragraph={{ rows: 4 }} />;
  if (detail.error || d == null) return <NotFound />; // detail 404s out-of-scope (new endpoint) ⇒ NotFound (A1)

  const name = programmeName(d, locale);
  const progSessions = (sessionsRes.data?.sessions ?? []).filter((s) => s.programme_id === d.programme_id);
  const now = Date.now();
  const nextSession = progSessions
    .filter((s) => new Date(s.starts_at).getTime() > now && !['cancelled', 'completed'].includes(s.status))
    .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime())[0] ?? null;
  const consent = (consentRes.data?.data ?? []).find((c) => c.student_id === d.student_id && c.programme_id === d.programme_id);
  const consentValue = consent
    ? (['sent', 'viewed'].includes(consent.status) ? t('studentHome.consentWaiting') : consent.status === 'signed' ? t('selfService.consentMet') : t(`consent.status.${consent.status}`))
    : '—';

  const act = async (id: string, path: 'book' | 'cancel') => {
    const r = await mutate(`/api/my/sessions/${id}/${path}`);
    if (r.ok) { void message.success(t(path === 'book' ? 'attendance.booked' : 'attendance.cancelled')); sessionsRes.reload(); }
    else void message.error(r.message ?? t('mutate.failed'));
  };

  // Journey: terminal-bad → status pill only; else the §3.4 stepper + the three stat tiles + what-next.
  const journey = isTerminal(d.status)
    ? <StatusTag domain="enrolmentStatus" value={d.status} />
    : (
      <JourneyStepper
        steps={SEG.map((lbl) => ({ title: t(`enrolCard.seg.${lbl}`) }))}
        current={currentIndex(d.status)}
        locale={locale}
        tiles={[
          { label: t('enrolSpace.tile.team'), value: d.team_name ?? t('enrolSpace.tile.noTeam') },
          { label: t('enrolSpace.tile.nextSession'), value: nextSession ? formatHkt(nextSession.starts_at, locale) : t('enrolSpace.tile.noSession') },
          { label: t('enrolCard.consent'), value: consentValue },
        ]}
        whatNext={WHATNEXT[d.status] ? t(`enrolSpace.whatNext.${WHATNEXT[d.status]}`) : undefined}
        whatNextLabel={t('enrolSpace.whatNextLabel')}
      />
    );

  const sessions = progSessions.length === 0
    ? <EmptyState message={null} />
    : (
      <Space direction="vertical" size="middle" style={{ width: '100%' }}>
        {progSessions.map((s) => <SessionRow key={s.id} s={s} isStudent={isStudent} locale={locale} onAct={act} />)}
      </Space>
    );

  return (
    <div style={{ maxWidth: 900 }}>
      <ProgrammeBandHeader
        // C5-CONSUME: real banner_url from the detail read (S-READ-1). Null ⇒ deterministically-failing src ⇒
        // HeroBanner falls back to the §3.2 brand gradient. The data:, hack is gone.
        image={{ src: d.banner_url || 'data:,', alt: name }}
        imageFallback={<div style={{ width: '100%', height: '100%', background: 'linear-gradient(135deg, var(--ka-cat-stem), var(--ka-card))' }} />}
        name={name}
        status={<StatusTag domain="enrolmentStatus" value={d.status} />}
      />
      <Tabs
        style={{ marginTop: 16 }}
        items={TABS.map((k) => ({
          key: k,
          label: t(`enrolSpace.tab.${k}`),
          // Journey + Team + Sessions + Results are filled; Tracker stays icon-only empty (blocked — no gap copy).
          children: k === 'journey' ? journey
            : k === 'team' ? <TeamPanel programmeId={d.programme_id} status={d.status} isStudent={isStudent} viewerId={identity?.id} />
            : k === 'sessions' ? sessions
            : k === 'results' ? <ResultsPanel programmeId={d.programme_id} studentId={d.student_id} />
            : <EmptyState message={null} />,
        }))}
      />
    </div>
  );
}

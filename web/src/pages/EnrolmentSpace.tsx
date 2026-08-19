// C1-SHELL / C4-TABS-1 / C5-CONSUME — the family SCOPED PROGRAMME SPACE (D-1: keyed on ENROLMENT_ID). C5-CONSUME
// spends the S-READ-1 reads: the space now reads GET /api/enrolments/{id} (detail) directly — the client-side
// list-filter interim is gone — and fills the Journey stat-tile row (Team from team_name, Next session from the
// now-scoped session read, Consent from consent-requests) and the Sessions tab (this enrolment's sessions via
// programme_id; book/cancel are student-only). The band image is the detail read's banner_url (the data:, hack
// is gone). Both personas reach it: student → /my/sessions, guardian → /my/students/{id}/sessions.
//
// C7-RESULTS fills the Results tab: the existing embargoed assessment reads, coarsened to a released? bit (see
// ResultsPanel). STILL BLOCKED, each with a named blocker: Team/Tracker tabs (need team-roster/tracker reads);
// stepper per-knot DATES (per-transition timestamps live only in audit_events); the tile .ev sub-line (JourneyTile
// has no sub slot + no signature-detail read); member_count (tm_read admits only the viewer's own row); session
// LOCATION + MATERIALS (not in the session read). A 404 from the detail endpoint (out-of-scope) → NotFound (A1).
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { App, Button, Skeleton, Space, Tabs, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource } from '../api/useResource';
import { authFetch } from '../auth/session';
import { mutate } from '../api/mutate';
import { useIdentity } from '../auth/identity';
import { programmeName } from '../display/names';
import { StatusTag } from '../display/status';
import { formatHkt } from '../display/date';
import { SEG, WHATNEXT, currentIndex, isTerminal } from '../display/enrolmentJourney';
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
          // Journey + Sessions + Results are filled; Team/Tracker stay icon-only empty (blocked — no gap copy).
          children: k === 'journey' ? journey
            : k === 'sessions' ? sessions
            : k === 'results' ? <ResultsPanel programmeId={d.programme_id} studentId={d.student_id} />
            : <EmptyState message={null} />,
        }))}
      />
    </div>
  );
}

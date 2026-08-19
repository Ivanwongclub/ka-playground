// C1-SHELL / C4-TABS-1 / C5-CONSUME — the family SCOPED PROGRAMME SPACE (D-1: keyed on ENROLMENT_ID). C5-CONSUME
// spends the S-READ-1 reads: the space now reads GET /api/enrolments/{id} (detail) directly — the client-side
// list-filter interim is gone — and fills the Journey stat-tile row (Team from team_name, Next session from the
// now-scoped session read, Consent from consent-requests) and the Sessions tab (this enrolment's sessions via
// programme_id; book/cancel are student-only). The band image is the detail read's banner_url (the data:, hack
// is gone). Both personas reach it: student → /my/sessions, guardian → /my/students/{id}/sessions.
//
// STILL BLOCKED, each with a named blocker: Team/Tracker/Results tabs (need team-roster/tracker/assessment reads);
// stepper per-knot DATES (per-transition timestamps live only in audit_events); the tile .ev sub-line (JourneyTile
// has no sub slot + no signature-detail read); member_count (tm_read admits only the viewer's own row); session
// LOCATION + MATERIALS (not in the session read). A 404 from the detail endpoint (out-of-scope) → NotFound (A1).
import { useParams } from 'react-router-dom';
import { App, Button, Skeleton, Space, Tabs, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource } from '../api/useResource';
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
          // Journey + Sessions are filled; Team/Tracker/Results stay icon-only empty (blocked — no gap copy).
          children: k === 'journey' ? journey : k === 'sessions' ? sessions : <EmptyState message={null} />,
        }))}
      />
    </div>
  );
}

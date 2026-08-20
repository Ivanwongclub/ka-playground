// B1-STU-HOME — the STUDENT persona home, composed to the prototype's `stu-home` (L422–447).
// CLIENT ONLY: no server change, no new read. Two reads, both already served:
//   • GET /api/my/sessions   — the NEXT-UP card (it is the only read carrying booking_status; the enrolment
//     list's next_session_* columns are the PROGRAMME's next session with no booking join, so they cannot
//     answer "your next BOOKED session" — EnrolmentController:70-72).
//   • GET /api/enrolments    — the "My programmes" cards (S-READ-2 widened: banner_url · team_name ·
//     consent_status · programme names), which is what retired the previous /api/teams and
//     /api/consent-requests reads. 4 reads → 2.
//
// OMITTED, never placeholdered (verdict, B1-STU-HOME STEP 1):
//   · the session VENUE ("· CityU Campus") — NOT-SERVED: SessionReadController returns no location. FLAG.
//   · "· 5 members" on the team row — NOT-SERVED (read-narrow): member_count is served by the ROSTER read
//     but was dropped from the LIST read for cost (S-READ-2). The known RW. FLAG.
//   · [Remind] — DOMAIN-UNBUILT (D6 notifications).
//   · a first-name greeting — MODEL-LACKS-THE-FIELD: `users` carries one `name` string and HK names are
//     family-first, so token-splitting would greet the family name. The full display name renders instead.
// Action-golds: 0 — the prototype's only button is btn-quiet, and it is omitted anyway.
import { Typography } from 'antd';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { asset } from '../assets';
import { personName, programmeName } from '../display/names';
import { formatHkt } from '../display/date';
import { segItems } from '../display/enrolmentJourney';
import { HeroBanner, GlanceCard, StatusTag, useResource, DataBoundary } from '@/ds2';
import type { GlanceRow } from '@/ds2';

interface SessionRow { id: string; title: string; starts_at: string; ends_at: string; status: string; programme_id: number; booking_status?: string | null }
interface Enrolment {
  id: string; programme_id: number; status: string;
  programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null;
  banner_url: string | null; team_name: string | null; consent_status: string | null;
  // NB: no amount field is typed here — a student must never receive one (P-3/B-18).
}

const TERMINAL_SESSION = new Set(['completed', 'cancelled']);
const BOOKED = new Set(['booked', 'waitlisted']);
const CONSENT_WAITING = new Set(['sent', 'viewed']);

// Normalise the API timestamp (as display/date does) so > compares honestly.
const ts = (s: string) => Date.parse(s.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));

/** Soonest upcoming BOOKED/waitlisted session: starts_at > now ∧ status ∉ {completed, cancelled}. */
function nextSession(sessions: SessionRow[]): SessionRow | null {
  const now = Date.now();
  return sessions
    .filter((s) => BOOKED.has(s.booking_status ?? '') && !TERMINAL_SESSION.has(s.status) && ts(s.starts_at) > now)
    .sort((a, b) => ts(a.starts_at) - ts(b.starts_at))[0] ?? null;
}

/**
 * The time-of-day eyebrow, resolved in HKT — never the device clock. A programme runs on Hong Kong time
 * (CLAUDE.md: single timezone), so a student travelling must not be greeted by their airport's hour.
 * Three standalone phrases, no interpolation: the greeting and the name are separate elements in the
 * prototype (L424-425), which is also the only shape that translates cleanly into TC/SC.
 */
function greetingKey(): 'morning' | 'afternoon' | 'evening' {
  const hkt = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Hong_Kong', hour: '2-digit', hour12: false }).format(new Date()));
  return hkt < 12 ? 'morning' : hkt < 18 ? 'afternoon' : 'evening';
}

/**
 * The ONE state row the prototype's home card carries (the fuller value-row set lives on /programmes).
 * Team once there is one; else the consent-waiting pill while the guardian has not signed. Nothing to say
 * ⇒ no row, rather than an empty label.
 */
function stateRow(e: Enrolment, t: (k: string) => string): GlanceRow[] {
  if (e.team_name) {
    return [{ label: t('enrolCard.team'), value: { text: e.team_name } }];
  }
  if (CONSENT_WAITING.has(e.consent_status ?? '')) {
    // A pend PILL here (prototype L444), where /programmes renders the same fact as plain text — the
    // prototype draws home as the attention surface and the list as the value surface. Kit §3.1: no dot.
    return [{ label: t('enrolCard.consent'), value: { tag: <StatusTag domain="consentWait" value="waiting" /> } }];
  }
  return [];
}

export function StudentHome() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { identity } = useIdentity();
  const navigate = useNavigate();

  const sessions = useResource<{ sessions: SessionRow[] }>('/api/my/sessions');
  const enr = useResource<{ data: Enrolment[] }>('/api/enrolments');

  const enrolments = enr.data?.data ?? [];
  const next = nextSession(sessions.data?.sessions ?? []);
  // The prototype drills NEXT UP into that session's enrolment SPACE (setProg + go('stu-space')). We resolve
  // the enrolment by the session's programme_id; with no match the card renders INERT rather than guess a
  // target — a drill that lands somewhere plausible-but-wrong is worse than no drill.
  const nextEnrolment = next ? enrolments.find((e) => e.programme_id === next.programme_id) ?? null : null;

  const nextUp = next && (
    <div
      style={{
        borderRadius: 'var(--ka-r-md)', padding: '22px 24px',
        // the prototype's pend gradient, token-backed: --ka-pending IS #4D7CF0 = its rgba(77,124,240).
        background: 'linear-gradient(120deg, var(--ka-pending-tint), transparent 55%), var(--ka-card)',
        border: '1px solid var(--ka-pending-line)',
      }}
    >
      <div style={{ fontSize: 12, letterSpacing: '.14em', textTransform: 'uppercase', fontWeight: 500, color: 'var(--ka-pending)', marginBottom: 8 }}>
        {t('studentHome.nextUp')}
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
        <div style={{ minWidth: 0 }}>
          <Typography.Text strong style={{ display: 'block', fontSize: 18 }}>{next.title}</Typography.Text>
          {/* programme name only — the venue is not served (FLAG). Resolved from the enrolment already read. */}
          {nextEnrolment && <Typography.Text type="secondary">{programmeName(nextEnrolment, locale)}</Typography.Text>}
        </div>
        <div style={{ textAlign: 'right', flex: '0 0 auto' }}>
          <Typography.Text strong style={{ display: 'block' }}>{formatHkt(next.starts_at, locale)}</Typography.Text>
          <StatusTag domain="bookingStatus" value={next.booking_status} />
        </div>
      </div>
    </div>
  );

  return (
    <div data-density="product">
      {/* §C2 hero — the prototype's photo hero (`--img-a` + two scrims, L327-338), greeting eyebrow over it. */}
      <HeroBanner image={{ src: asset('auth/featured-sc5.jpg'), alt: '' }} height="band">
        <div style={{ fontSize: 12, letterSpacing: '.14em', textTransform: 'uppercase', fontWeight: 500 }}>
          {t(`studentHome.greeting.${greetingKey()}`)}
        </div>
        {/* the prototype's <h2> (L425) — a real heading, so the page has an outline a screen reader can
            navigate (h2 name → h3 section), not two sizes of div. */}
        <h2 style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 31, lineHeight: 1.15, margin: 0 }}>
          {personName(identity?.name ?? '')}
        </h2>
      </HeroBanner>

      {/* NEXT UP — ONE interactive: a Link wrapping the whole card. No inner actions, so nothing nests
          (the GUA-FIX a11y ruling). Without a resolvable enrolment it renders inert. */}
      {nextUp && (
        <div style={{ marginTop: 'var(--ka-zone-gap)' }}>
          {nextEnrolment
            ? <Link to={`/enrolments/${nextEnrolment.id}`} style={{ display: 'block', color: 'inherit' }}>{nextUp}</Link>
            : nextUp}
        </div>
      )}

      <div style={{ marginTop: 'var(--ka-zone-gap)' }}>
        {/* h3 keeps the outline h2 → h3 (the prototype's h3.sect); the 15.5px size is the prototype's, not
            antd's level-3 default. */}
        <Typography.Title level={3} style={{ marginBottom: 12, fontSize: 15.5, fontWeight: 700 }}>{t('enrolCard.title')}</Typography.Title>
        <DataBoundary loading={enr.loading} error={enr.error} empty={enrolments.length === 0}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--ka-card-gap, 16px)' }}>
            {enrolments.map((e) => (
              <GlanceCard
                key={e.id}
                image={{ src: e.banner_url || 'data:,', alt: programmeName(e, locale) }}
                imageFallback={<div style={{ width: '100%', height: '100%', background: 'linear-gradient(135deg, var(--ka-cat-stem), var(--ka-card))' }} />}
                title={programmeName(e, locale)}
                status={<StatusTag domain="enrolmentStatus" value={e.status} />}
                // segItems returns undefined for a terminal-bad status — no segbar, the pill carries it.
                segments={segItems(e.status, t)}
                rows={stateRow(e, t)}
                // Whole-card drill: with [Remind] omitted these cards have ZERO interactive children, so
                // GlanceCard's role="button" + tabIndex + Enter/Space is the complete affordance. Same card,
                // same target, same behaviour as /programmes.
                onClick={() => navigate(`/enrolments/${e.id}`)}
              />
            ))}
          </div>
        </DataBoundary>
      </div>
    </div>
  );
}

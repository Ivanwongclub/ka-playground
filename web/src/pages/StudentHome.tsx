// R1-S — the STUDENT persona home. Action-first, built from the DS2 v2 primitives (HeroBanner/TaskCard/
// StatCard/EmptyState). Routed by Dashboard for the verified student-only predicate. Reads only: four
// PARALLEL client reads (useResource), each card showing its own Skeleton — no aggregate endpoint, no
// server change. Product density (§6). Consent is framed as WAITING ON THE GUARDIAN — never a student task.
import { Col, Row, Skeleton } from 'antd';
import { CalendarClock, FileSignature, GraduationCap, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { asset } from '../assets';
import { formatHkt, formatHktTime } from '../display/date';
import { HeroBanner, TaskCard, StatCard, EmptyState, SubPanel, StatusTag, useResource } from '@/ds2';

interface SessionRow { id: string; title: string; starts_at: string; ends_at: string; status: string; booking_status?: string | null }
interface TeamRow { id: string; name: string; status: string; member_count: number }
interface EnrolRow { status: string }
interface ConsentRow { status: string }

const TERMINAL_SESSION = new Set(['completed', 'cancelled']);
const BOOKED = new Set(['booked', 'waitlisted']);
const OPEN_CONSENT = new Set(['sent', 'viewed']);

// Normalise the API timestamp (as display/date does) so > compares honestly.
const ts = (s: string) => Date.parse(s.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));

/** Soonest upcoming booked/waitlisted session: starts_at > now ∧ status ∉ {completed, cancelled}. */
function nextSession(sessions: SessionRow[]): SessionRow | null {
  const now = Date.now();
  const upcoming = sessions
    .filter((s) => BOOKED.has(s.booking_status ?? '') && !TERMINAL_SESSION.has(s.status) && ts(s.starts_at) > now)
    .sort((a, b) => ts(a.starts_at) - ts(b.starts_at));
  return upcoming[0] ?? null;
}

export function StudentHome() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { identity } = useIdentity();

  // Four PARALLEL reads — each card renders its own Skeleton while its source loads (D-S1).
  const sessions = useResource<{ sessions: SessionRow[] }>('/api/my/sessions');
  const teams = useResource<{ data: TeamRow[] }>('/api/teams');
  const enrolments = useResource<{ data: EnrolRow[] }>('/api/enrolments');
  const consents = useResource<{ data: ConsentRow[] }>('/api/consent-requests');

  const next = nextSession(sessions.data?.sessions ?? []);
  const myTeam = (teams.data?.data ?? []).find((x) => x.member_count >= 1) ?? null;
  const pooled = (enrolments.data?.data ?? []).some((e) => e.status === 'in_pool');
  const enrolCount = (enrolments.data?.data ?? []).length;
  const consentWaiting = (consents.data?.data ?? []).filter((c) => OPEN_CONSENT.has(c.status)).length;

  const greeting = t('dashboard.greeting', { name: identity?.name ?? '' });

  return (
    <div style={{ maxWidth: 1100 }} data-density="product">
      <HeroBanner image={{ src: asset('auth/featured-sc5.jpg'), alt: '' }} height="band">
        <div style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 24 }}>{greeting}</div>
        <div style={{ fontSize: 13 }}>{t('dashboard.subtitle')}</div>
      </HeroBanner>

      {/* R1-S2 balance: the two TaskCards on one equal-height row; the StatCards on their own row below.
          AL-1: inter-zone gap is --ka-zone-gap (24px product); the [16,16] gutter is the intra-zone
          --ka-card-gap value (AntD gutter needs a numeric literal, so it stays 16 — RULING 2). */}
      <Row gutter={[16, 16]} align="stretch" style={{ marginTop: 'var(--ka-zone-gap)' }}>
        {/* 1 — NEXT SESSION (action-first): the soonest booked/waitlisted upcoming session, or a designed empty. */}
        <Col xs={24} md={12}>
          {sessions.loading ? (
            <Skeleton active paragraph={{ rows: 2 }} />
          ) : next ? (
            <TaskCard
              icon={<CalendarClock size={18} />}
              title={next.title}
              context={
                <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                  {formatHkt(next.starts_at, locale)}{' – '}{formatHktTime(next.ends_at, locale)}
                  <StatusTag domain="bookingStatus" value={next.booking_status} />
                </span>
              }
              cta={{ label: t('studentHome.viewSessions'), to: '/my/sessions' }}
            />
          ) : (
            <SubPanel tone="neutral">
              <EmptyState
                size="inline"
                icon={<CalendarClock size={28} />}
                message={t('studentHome.noNextSession')}
                detail={t('studentHome.noNextSessionDetail')}
                cta={{ label: t('studentHome.viewSessions'), to: '/my/sessions' }}
              />
            </SubPanel>
          )}
        </Col>

        {/* 2 — MY TEAM: team + status, or the form/join prompt when pooled (or not yet on a team). */}
        <Col xs={24} md={12}>
          {teams.loading || enrolments.loading ? (
            <Skeleton active paragraph={{ rows: 2 }} />
          ) : (
            <TaskCard
              icon={<Users size={18} />}
              title={myTeam ? myTeam.name : t('studentHome.formOrJoin')}
              context={
                myTeam ? (
                  <StatusTag domain="teamStatus" value={myTeam.status} />
                ) : (
                  pooled ? t('studentHome.formOrJoinDetail') : t('studentHome.teamNone')
                )
              }
              cta={{ label: t('studentHome.viewTeam'), to: '/my/team' }}
            />
          )}
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 'var(--ka-zone-gap)' }}>
        {/* 3 — CONSENT-WAITING: a StatCard (count), framed WAITING ON YOUR GUARDIAN. NOT a student task, no
            urgency chip. Shown only when something is actually pending. */}
        {!consents.loading && consentWaiting > 0 && (
          <Col xs={24} sm={12}>
            <StatCard
              label={t('studentHome.consentWaiting')}
              value={consentWaiting}
              icon={<FileSignature size={18} />}
              sub={t('studentHome.consentWaitingSub')}
              to="/consents"
            />
          </Col>
        )}

        {/* 4 — ENROLMENTS (folded in from the old count tile): full-width when consent is hidden (no dead space). */}
        <Col xs={24} sm={consentWaiting > 0 ? 12 : 24}>
          {enrolments.loading ? (
            <Skeleton active paragraph={{ rows: 1 }} title={{ width: '55%' }} />
          ) : (
            <StatCard label={t('dashboard.enrolments')} value={enrolCount} icon={<GraduationCap size={18} />} to="/enrolments" />
          )}
        </Col>
      </Row>
    </div>
  );
}

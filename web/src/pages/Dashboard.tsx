// S-UX1 — role-scoped landing, replacing the empty placeholder at `/`. Composed from
// EXISTING RLS-scoped endpoints (D-UX1.2: composition first). Each card is gated by the
// same permission as its underlying screen, so the dashboard never shows a number the
// caller could not open. Counts only — money formatting is S-UX2a's shared kit, not here.
import { useEffect, useState } from 'react';
import { Col, Row, Skeleton, Typography } from 'antd';
import { CalendarCheck, CircleAlert, FileSignature, GraduationCap, Link2, Scale, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';
import { useIdentity } from '../auth/identity';
import { isStudentActor } from '../nav';
import { StatCard } from '@/ds2'; // DS2 — migrated to the shared StatCard primitive (was a local tile)
import { StudentHome } from './StudentHome';
import { GuardianHome } from './GuardianHome';

interface Metric {
  key: string;
  labelKey: string;
  value: number;
  icon: React.ReactNode;
  to: string;
  alert?: boolean; // render the value in the alert colour (e.g. issuance gaps > 0)
}

const OPEN_CONSENT = new Set(['sent', 'viewed']);
const SETTLED_LINK = new Set(['paid', 'expired', 'void', 'cancelled']);
const TERMINAL_SESSION = new Set(['completed', 'cancelled']);

// S-FIX-UX-1 D3: upcoming = a future start (API timestamp normalised as in display/date) that has not
// completed/cancelled. Module-level so the metric line carries no `>` (the i18n-check JSX heuristic).
function upcomingSessionCount(sessions: { starts_at: string; status: string }[]): number {
  const now = Date.now();
  return sessions.filter((s) => {
    if (TERMINAL_SESSION.has(s.status)) return false;
    const t = Date.parse(s.starts_at.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
    return !Number.isNaN(t) && t > now;
  }).length;
}

// R1-S — the persona router. It routes the WHOLE home. The student predicate is verified STUDENT-ONLY
// against config/permission-matrix.php + the seeded roles: enrolment.view ∧ events.rsvp ∧ ¬operations.manage.
// (teams.view ∧ ¬operations.manage — the first proposal — was ALSO satisfied by teacher and school_admin;
// events.rsvp is the student discriminator, and ¬operations.manage excludes super_admin's '*'.) Every
// non-student renders PersonaMetrics — a VERBATIM extraction of the prior Dashboard body — so their output
// is byte-identical to before.
export function Dashboard() {
  const { has } = useIdentity();
  if (isStudentActor(has)) return <StudentHome />;
  // R1-G — the GUARDIAN persona home. consent.sign is guardian-EXCLUSIVE: it is a guardian-role-only
  // permission AND listed in capability_forbidden, so no capability group (not even super_admin's '*')
  // can carry it (guarded by the authz.consent_sign_exclusive nightly assertion). Every non-guardian,
  // non-student still renders PersonaMetrics — byte-identical.
  if (has('consent.sign')) return <GuardianHome />;
  return <PersonaMetrics />;
}

function PersonaMetrics() {
  const { t } = useTranslation();
  const { identity, has } = useIdentity();
  const [metrics, setMetrics] = useState<Metric[] | null>(null);

  useEffect(() => {
    if (!identity) return;
    let alive = true;

    const json = async (url: string): Promise<any | null> => {
      try {
        const r = await authFetch(url);
        return r.ok ? await r.json() : null;
      } catch {
        return null;
      }
    };

    async function load() {
      // S-UX2a: the per-role reads run in PARALLEL (Promise.all), not sequentially.
      const tasks: Promise<Metric[]>[] = [];
      if (has('enrolment.view')) {
        tasks.push(
          json('/api/enrolments').then((d) => [
            { key: 'enrolments', labelKey: 'dashboard.enrolments', value: (d?.data ?? []).length, icon: <GraduationCap size={18} aria-hidden />, to: '/enrolments' },
          ]),
        );
      }
      if (has('consent.view')) {
        tasks.push(
          json('/api/consent-requests').then((d) => [
            { key: 'consent', labelKey: 'dashboard.openConsent', value: (d?.data ?? []).filter((r: { status: string }) => OPEN_CONSENT.has(r.status)).length, icon: <FileSignature size={18} aria-hidden />, to: '/consents' },
          ]),
        );
      }
      if (identity?.role === 'guardian') {
        tasks.push(
          json('/api/my/payment-links').then((d) => [
            { key: 'links', labelKey: 'dashboard.liveLinks', value: (d?.data ?? []).filter((r: { status: string }) => !SETTLED_LINK.has(r.status)).length, icon: <Link2 size={18} aria-hidden />, to: '/my/payments' },
          ]),
        );
      }
      // S-FIX-UX-1 D3: teacher (mentor) tile — same gate as the mentor nav item (teams.approve ∧
      // ¬operations.manage). Upcoming = a future start that hasn't completed/cancelled. Opens /attendance.
      if (has('teams.approve') && !has('operations.manage')) {
        tasks.push(
          json('/api/my/mentor/sessions').then((d) => [
            { key: 'mentorSessions', labelKey: 'dashboard.mentorSessions', value: upcomingSessionCount(d?.sessions ?? []), icon: <CalendarCheck size={18} aria-hidden />, to: '/attendance' },
          ]),
        );
      }
      if (has('audit.read')) {
        tasks.push(
          json('/api/reports/enrolment-pool').then((d) => {
            const gaps = (d?.issuance_gaps ?? []).length;
            return [
              { key: 'pool', labelKey: 'dashboard.poolProgrammes', value: (d?.pool_by_programme ?? []).length, icon: <Users size={18} aria-hidden />, to: '/admin/enrolment-pool' },
              { key: 'gaps', labelKey: 'dashboard.issuanceGaps', value: gaps, icon: <CircleAlert size={18} aria-hidden />, to: '/admin/enrolment-pool', alert: gaps > 0 },
            ];
          }),
        );
      }
      if (has('finance.record')) {
        tasks.push(
          json('/api/reports/financial-integrity').then((d) => [
            { key: 'orders', labelKey: 'dashboard.orders', value: (d?.orders ?? []).length, icon: <Scale size={18} aria-hidden />, to: '/admin/financial-integrity' },
          ]),
        );
      }

      const results = await Promise.all(tasks);
      if (alive) setMetrics(results.flat());
    }

    void load();
    return () => {
      alive = false;
    };
  }, [identity, has]);

  return (
    <div style={{ maxWidth: 1100 }}>
      <Typography.Title level={3} style={{ marginTop: 0 }}>
        {t('dashboard.greeting', { name: identity?.name ?? '' })}
      </Typography.Title>
      <Typography.Paragraph type="secondary">{t('dashboard.subtitle')}</Typography.Paragraph>

      {metrics === null ? (
        // R4: skeleton placeholders holding the KPI grid layout (composed multi-read) — not a top-level Spin.
        <Row gutter={[16, 16]}>
          {[0, 1, 2].map((i) => (
            <Col key={i} xs={24} sm={12} md={8}>
              <Skeleton active paragraph={{ rows: 1 }} title={{ width: '55%' }} />
            </Col>
          ))}
        </Row>
      ) : metrics.length === 0 ? (
        <Typography.Paragraph type="secondary">{t('dashboard.empty')}</Typography.Paragraph>
      ) : (
        <Row gutter={[16, 16]}>
          {metrics.map((m) => (
            <Col key={m.key} xs={24} sm={12} md={8}>
              {/* R0-B2: the StatCard is the interactive element — its built-in `to` (a Link) provides the
                  drill-down (role=button/keyboard/focus) the old .ka-dash-kpi wrapper hand-wired. */}
              <StatCard label={t(m.labelKey)} value={m.value} icon={m.icon} alert={m.alert} to={m.to} />
            </Col>
          ))}
        </Row>
      )}
    </div>
  );
}

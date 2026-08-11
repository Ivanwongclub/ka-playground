// R1-G — the GUARDIAN persona home (the PAYING persona). Action-first, same weight/primitives as
// StudentHome (HeroBanner/TaskCard/StatCard/EmptyState). Routed by Dashboard for the guardian-exclusive
// predicate (consent.sign — capability_forbidden bars it from every capability group). Reads ONLY: three
// PARALLEL client reads (useResource), no aggregate endpoint, no server change. Money appears here for the
// first time (formatMoney; the only computation is the client-side outstanding SUM). Consent is the
// guardian's OWN legal act — framed as a task, CTA links to the ceremony, never alters it.
import { Col, Row, Skeleton } from 'antd';
import { CreditCard, FileSignature, GraduationCap, Users } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { asset } from '../assets';
import { personName } from '../display/names';
import { formatMoney } from '../display/money';
import { formatHkt } from '../display/date';
import {
  HeroBanner, TaskCard, StatCard, EmptyState, SubPanel, useResource,
  urgencyLevel, urgencyDays, urgencyLabel, URGENCY,
} from '@/ds2';

interface ConsentRow { id: string; status: string; expires_at: string | null; student_name: string | null; programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null }
interface OrderRow { id: string; student_id: number; payer_party: string; status: string; total_amount_minor: number; currency: string; payment_due_at: string | null; student_name: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string }
interface EnrolRow { student_id: number }

const OPEN_CONSENT = new Set(['sent', 'viewed']);
const CONSENT_CAP = 3;
const PAYMENT_CAP = 2;

// The API timestamp normaliser (mirrors display/date + urgency) so soonest-first sorts honestly.
const ts = (s: string | null): number => (s ? Date.parse(s.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')) : Number.POSITIVE_INFINITY);
function progName(r: { programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null }, locale: KaLocale): string {
  return (locale === 'zh-TC' ? r.programme_name_tc : locale === 'zh-SC' ? r.programme_name_sc : r.programme_name_en) || r.programme_name_en || '';
}

export function GuardianHome() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { identity } = useIdentity();

  // Three PARALLEL reads — each zone renders its own Skeleton while its source loads.
  const consents = useResource<{ data: ConsentRow[] }>('/api/consent-requests');
  const orders = useResource<{ data: OrderRow[] }>('/api/orders');
  const enrolments = useResource<{ data: EnrolRow[] }>('/api/enrolments');

  // Pending consent — the guardian's own signature. Soonest deadline first; capped, with a view-all overflow.
  const openConsents = (consents.data?.data ?? [])
    .filter((c) => OPEN_CONSENT.has(c.status))
    .sort((a, b) => ts(a.expires_at) - ts(b.expires_at));

  // Payable orders — issued (unpaid) AND family-payable (school-payer orders are the school's to settle).
  const payable = (orders.data?.data ?? [])
    .filter((o) => o.status === 'issued' && (o.payer_party === 'guardian' || o.payer_party === 'student'))
    .sort((a, b) => ts(a.payment_due_at) - ts(b.payment_due_at));
  const outstanding = payable.reduce((sum, o) => sum + o.total_amount_minor, 0);
  const currency = payable[0]?.currency ?? 'HKD';

  const childCount = new Set((enrolments.data?.data ?? []).map((e) => e.student_id)).size;
  const enrolCount = (enrolments.data?.data ?? []).length;

  const loadingWork = consents.loading || orders.loading;
  const nothingPending = !loadingWork && openConsents.length === 0 && payable.length === 0;
  // Hoisted out of JSX so the overflow test carries no `>` (the i18n-check JSX heuristic, as Dashboard does).
  const moreConsents = openConsents.length > CONSENT_CAP;
  const morePayments = payable.length > PAYMENT_CAP;

  // AL-2: consent + payment TaskCards share ONE grid so an odd last card can span full width (no dead
  // half-column). Consents first (the guardian's own legal act), then the payable orders.
  const pendingCards = [
    ...openConsents.slice(0, CONSENT_CAP).map((c) => {
      const lvl = urgencyLevel(c.expires_at, URGENCY.consent);
      return {
        key: `c-${c.id}`,
        render: () => (
          <TaskCard
            icon={<FileSignature size={18} />}
            title={personName(c.student_name)}
            context={progName(c, locale)}
            urgency={lvl}
            urgencyLabel={lvl !== 'none' ? urgencyLabel(lvl, urgencyDays(c.expires_at), t) : undefined}
            cta={{ label: t('guardianHome.consentCta'), to: `/consents/${c.id}` }}
          />
        ),
      };
    }),
    ...payable.slice(0, PAYMENT_CAP).map((o) => {
      const lvl = urgencyLevel(o.payment_due_at, URGENCY.payment);
      // AD-4: the guardian payment card now names the child — "Demo Student A6 · Summer STEM 2026".
      const who = o.student_name ? `${personName(o.student_name)} · ${progName(o, locale)}` : progName(o, locale);
      return {
        key: `p-${o.id}`,
        render: () => (
          <TaskCard
            icon={<CreditCard size={18} />}
            title={who}
            context={`${formatMoney(o.total_amount_minor, o.currency, locale)}${o.payment_due_at ? ` · ${t('selfService.due')} ${formatHkt(o.payment_due_at, locale)}` : ''}`}
            urgency={lvl}
            urgencyLabel={lvl !== 'none' ? urgencyLabel(lvl, urgencyDays(o.payment_due_at), t) : undefined}
            cta={{ label: t('guardianHome.paymentCta'), to: '/my/payments' }}
          />
        ),
      };
    }),
  ];

  return (
    <div style={{ maxWidth: 1100 }} data-density="product">
      <HeroBanner image={{ src: asset('auth/featured-sc5.jpg'), alt: '' }} height="band">
        <div style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 24 }}>{t('dashboard.greeting', { name: identity?.name ?? '' })}</div>
        <div style={{ fontSize: 13 }}>{t('guardianHome.subtitle')}</div>
      </HeroBanner>

      {/* PENDING WORK — the family's outstanding actions, front and centre. */}
      {loadingWork ? (
        <Row gutter={[16, 16]} style={{ marginTop: 'var(--ka-zone-gap)' }}>
          {[0, 1].map((i) => <Col key={i} xs={24} md={12}><Skeleton active paragraph={{ rows: 2 }} /></Col>)}
        </Row>
      ) : nothingPending ? (
        <div style={{ marginTop: 'var(--ka-zone-gap)' }}>
          <SubPanel tone="neutral">
            <EmptyState size="inline" icon={<FileSignature size={28} />} message={t('guardianHome.allClear')} detail={t('guardianHome.allClearDetail')} />
          </SubPanel>
        </div>
      ) : (
        <Row gutter={[16, 16]} align="stretch" style={{ marginTop: 'var(--ka-zone-gap)' }}>
          {pendingCards.map((card, i) => {
            const full = i === pendingCards.length - 1 && pendingCards.length % 2 === 1;
            return <Col key={card.key} xs={24} md={full ? 24 : 12}>{card.render()}</Col>;
          })}
          {moreConsents && (
            <Col xs={24}><Link to="/consents">{t('guardianHome.viewAllConsents', { count: openConsents.length })}</Link></Col>
          )}
          {morePayments && (
            <Col xs={24}><Link to="/my/payments">{t('guardianHome.viewAllPayments', { count: payable.length })}</Link></Col>
          )}
        </Row>
      )}

      {/* AT A GLANCE — children, the outstanding total (client-summed, display-only), enrolments. */}
      <Row gutter={[16, 16]} style={{ marginTop: 'var(--ka-zone-gap)' }}>
        <Col xs={24} sm={8}>
          {enrolments.loading ? <Skeleton active paragraph={{ rows: 1 }} title={{ width: '55%' }} />
            : <StatCard label={t('guardianHome.children')} value={childCount} icon={<Users size={18} />} to="/my/children" />}
        </Col>
        <Col xs={24} sm={8}>
          {orders.loading ? <Skeleton active paragraph={{ rows: 1 }} title={{ width: '55%' }} />
            : <StatCard label={t('guardianHome.outstanding')} value={formatMoney(outstanding, currency, locale)} icon={<CreditCard size={18} />} accent={outstanding > 0 ? 'gold' : 'default'} to="/my/payments" />}
        </Col>
        <Col xs={24} sm={8}>
          {enrolments.loading ? <Skeleton active paragraph={{ rows: 1 }} title={{ width: '55%' }} />
            : <StatCard label={t('dashboard.enrolments')} value={enrolCount} icon={<GraduationCap size={18} />} to="/enrolments" />}
        </Col>
      </Row>
    </div>
  );
}

// B3-GUA-CHILD — the guardian's CHILD HUB (/my/children/:studentId), composed to the prototype's `gua-child`
// (L750-774): identity + a card per enrolment, each carrying the four chip rows and drilling into the scoped
// space. Replaces the Profile360 product-density composition AUDIT-2 classed WRONG-SHAPE for this route.
//
// CLIENT ONLY: no server change, no new read. FOUR reads, all already served, and the count is now FLAT —
// the previous page fanned out per enrolment and per assessment (2 base + 2 team + 1 per enrolment + 1 per
// released result; 7 for a 2-enrolment child with a team):
//   • GET /api/enrolments       — the child's enrolments, and the identity itself (student_name, school_name_*)
//   • GET /api/consent-requests — the consent request ID, which is the ceremony address /consents/{id} (B2)
//   • GET /api/orders           — fee amounts + order state (guardian amounts are permitted; P-3 is student-only)
//   • GET /api/receipts         — the receipt NUMBER for a settled fee. receipts_read is
//     `system OR EXISTS (SELECT 1 FROM orders o WHERE o.id = receipts.order_id)`, so a receipt inherits its
//     order's visibility: no new visibility path, just the artefact a paying family keeps.
//
// OMITTED, never placeholdered:
//   · the consent SIGNED DATE ("Signed 5 Jul") — NOT-SERVED: /api/consent-requests carries status + expires_at
//     but no signed_at (it lives on /api/consent-signatures). "Signed" renders without it; RW flagged, and
//     deliberately NOT worth a fifth read for a date.
//   · [Materials · n] — DOMAIN-UNBUILT: no session_materials table, no route, no read.
//   · `Request withdrawal…` — DOMAIN-UNBUILT for the family (the API chain exists, the UI does not, and the
//     mWd refund-window promise belongs to that DU card). Note for whoever builds it: in the block the
//     affordance is STATE-GATED — it appears on the confirmed card (L772) and not on the pooled one.
import { useMemo } from 'react';
import { Button, Typography, Skeleton } from 'antd';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { initials, personName, programmeName, schoolName } from '../display/names';
import { formatMoney } from '../display/money';
import { formatHkt } from '../display/date';
import { segItems } from '../display/enrolmentJourney';
import { GlanceCard, StatusTag, UrgencyChip, useResource, DataBoundary, urgencyLevel, urgencyDays, urgencyLabel, URGENCY } from '@/ds2';
import type { GlanceRow } from '@/ds2';
import { NotFound } from './NotFound';

interface Enrolment {
  id: string; programme_id: number; student_id: number; status: string; student_name: string | null;
  programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null;
  school_name_en: string | null; school_name_tc: string | null; school_name_sc: string | null;
  banner_url: string | null; team_name: string | null;
  consent_status: string | null; consent_expires_at: string | null;
  next_session_title: string | null; next_session_starts_at: string | null;
}
interface ConsentRow { id: string; programme_id: number; student_id: number; status: string }
interface OrderRow { id: string; enrolment_id: string; student_id: number; status: string; total_amount_minor: number; currency: string }
interface ReceiptRow { order_id: string; receipt_number: string }

const OPEN_CONSENT = new Set(['sent', 'viewed']);

export function ChildHub() {
  const { studentId } = useParams();
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const id = Number(studentId);

  const enr = useResource<{ data: Enrolment[] }>('/api/enrolments');
  const consents = useResource<{ data: ConsentRow[] }>('/api/consent-requests');
  const orders = useResource<{ data: OrderRow[] }>('/api/orders');
  const receipts = useResource<{ data: ReceiptRow[] }>('/api/receipts');

  const mine = useMemo(() => (enr.data?.data ?? []).filter((e) => e.student_id === id), [enr.data, id]);
  const receiptByOrder = useMemo(
    () => new Map((receipts.data?.data ?? []).map((r) => [r.order_id, r.receipt_number])),
    [receipts.data],
  );
  const orderByEnrolment = useMemo(
    () => new Map((orders.data?.data ?? []).map((o) => [o.enrolment_id, o])),
    [orders.data],
  );

  // The identity comes from the enrolment rows themselves — no extra read. A child with NO enrolments has no
  // name to render here (the same hole MyChildren and the guardian home carry); flagged as a read ruling
  // rather than guessed at.
  const name = mine[0]?.student_name ?? '';
  const school = mine[0] ? schoolName(mine[0], locale) : null;

  if (enr.loading) return <Skeleton active paragraph={{ rows: 6 }} />;
  if (enr.error || !Number.isFinite(id)) return <NotFound />;

  const rowsFor = (e: Enrolment): GlanceRow[] => {
    const rows: GlanceRow[] = [];

    // CONSENT — settled reads "Signed" (no date: not served); unsettled shows the countdown chip and the
    // card's ONE gold, addressed to the ceremony by the consent request's own id.
    const req = (consents.data?.data ?? []).find((c) => c.student_id === e.student_id && c.programme_id === e.programme_id);
    if (e.consent_status === 'signed') {
      rows.push({ label: t('enrolCard.consent'), value: { tag: <StatusTag domain="consentState" value="signed" /> } });
    } else if (OPEN_CONSENT.has(e.consent_status ?? '') && req) {
      // The block's unsettled row is a COUNTDOWN ("Expires in 6 days"), not a state label — so the shared
      // urgency treatment carries it. Past the 'soon' window UrgencyChip renders nothing, so the state pill
      // is the floor: the row always says something true.
      const level = urgencyLevel(e.consent_expires_at, URGENCY.consent);
      rows.push({ label: t('enrolCard.consent'), value: { action: (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 10 }}>
          {level === 'none'
            ? <StatusTag domain="consentState" value={e.consent_status ?? ''} />
            : <UrgencyChip level={level} label={urgencyLabel(level, urgencyDays(e.consent_expires_at), t)} />}
          <Link to={`/consents/${req.id}`}><Button type="primary" size="small" className="ka-cta">{t('guaChild.sign')}</Button></Link>
        </span>
      ) } });
    }

    // FEES — the amount is permitted here (guardian surface). Settled carries the receipt NUMBER, the one
    // artefact a paying family keeps; without a receipt row it reads "Settled" rather than inventing one.
    const order = orderByEnrolment.get(e.id);
    if (order) {
      const receipt = receiptByOrder.get(order.id);
      const settled = order.status === 'paid' || order.status === 'covered_by_invoice';
      rows.push({ label: t('enrolCard.fees'), value: { text: settled
        ? (receipt ? t('guaChild.settledWithReceipt', { receipt }) : t('guaChild.settled'))
        : t('guaChild.orderIssued', { amount: formatMoney(order.total_amount_minor, order.currency, locale) }) } });
    }

    // TEAM — the name once there is one, else the block's literal "Awaiting team formation".
    rows.push({ label: t('enrolCard.team'), value: { text: e.team_name ?? t('guaChild.awaitingTeam') } });

    // NEXT SESSION — title + start. [Materials · n] omitted (DOMAIN-UNBUILT).
    if (e.next_session_starts_at) {
      rows.push({ label: t('enrolCard.nextSession'), value: { text: `${e.next_session_title ?? ''} · ${formatHkt(e.next_session_starts_at, locale)}`.replace(/^ · /, '') } });
    }
    return rows;
  };

  return (
    <div data-density="product">
      <Link to="/my/children" style={{ display: 'inline-block', marginBottom: 20 }}>{t('guaChild.backToChildren')}</Link>

      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
        <span style={{ width: 46, height: 46, borderRadius: '50%', background: 'var(--ka-muted)', display: 'grid', placeItems: 'center', fontSize: 17, fontWeight: 600, color: 'var(--ka-muted-fg)', flex: 'none' }}>
          {initials(personName(name))}
        </span>
        <div>
          <Typography.Title level={2} style={{ fontSize: 21, margin: 0 }}>{personName(name)}</Typography.Title>
          {/* The school is a FACT about the child, not a destination: neutral, no dot, not actionable. It is
              rendered with the pill CLASSES rather than through StatusTag, because StatusTag's contract is a
              CLOSED enum → i18n label, and a school NAME is free text — passing it as a `value` would humanise
              a proper noun. Same markup StatusTag emits, no new component. */}
          {school && <span className="ant-tag ka-pill ka-pill--neutral">{school}</span>}
        </div>
      </div>

      <Typography.Title level={3} style={{ fontSize: 15.5, fontWeight: 700, margin: '18px 0 12px' }}>{t('guaChild.enrolments')}</Typography.Title>
      <DataBoundary loading={consents.loading || orders.loading} error={null} empty={mine.length === 0}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--ka-card-gap, 16px)' }}>
          {mine.map((e) => (
            <GlanceCard
              key={e.id}
              image={{ src: e.banner_url || 'data:,', alt: programmeName(e, locale) }}
              imageFallback={<div style={{ width: '100%', height: '100%', background: 'linear-gradient(135deg, var(--ka-cat-stem), var(--ka-card))' }} />}
              // TITLE-AS-LINK, and no whole-card onClick: this card carries a gold [Sign], so a clickable
              // card would nest interactives — invalid HTML, ambiguous in the a11y tree, and stopPropagation
              // is mouse-only (a keyboard user on Sign would fire both). A DELIBERATE divergence from the
              // block, which does exactly that at L768; same ruling as GUA-FIX and C6.
              // color:inherit — the title is a LINK for semantics and keyboard reach, but it is still the
              // card's NAME: antd's link blue would repaint the loudest text on the card and diverge from the
              // block, where the title is plain and the whole card carries the affordance.
              title={<Link to={`/enrolments/${e.id}`} style={{ color: 'inherit' }}>{programmeName(e, locale)}</Link>}
              status={<StatusTag domain="enrolmentStatus" value={e.status} />}
              segments={segItems(e.status, t)}
              rows={rowsFor(e)}
            />
          ))}
        </div>
      </DataBoundary>
    </div>
  );
}

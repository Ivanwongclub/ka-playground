// S-UX2a — closed-set enum → i18n label + colour (StatusTag), and the open-set humaniser
// (AuditAction) for audit/auth action codes (D-UX2a.1). An unknown code never renders raw:
// StatusTag humanises it; AuditAction always humanises and preserves the exact code beside it.
import { Tag, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import './status.css';

interface Entry {
  labelKey: string;
  color?: string; // antd Tag preset: success | processing | warning | error | gold | default …
}

// Domains present on the in-scope pages. Codes taken from the migrations' CHECK constraints.
const REGISTRY: Record<string, Record<string, Entry>> = {
  orderStatus: {
    issued: { labelKey: 'status.order.issued', color: 'processing' },
    paid: { labelKey: 'status.order.paid', color: 'success' },
    covered_by_invoice: { labelKey: 'status.order.covered_by_invoice', color: 'gold' },
    refunded: { labelKey: 'status.order.refunded', color: 'warning' },
    cancelled: { labelKey: 'status.order.cancelled', color: 'default' },
  },
  paymentOrigin: {
    provider: { labelKey: 'status.origin.provider', color: 'processing' },
    manual: { labelKey: 'status.origin.manual', color: 'default' },
  },
  paymentStatus: {
    pending_confirmation: { labelKey: 'status.payment.pending_confirmation', color: 'warning' },
    confirmed: { labelKey: 'status.payment.confirmed', color: 'success' },
    rejected: { labelKey: 'status.payment.rejected', color: 'error' },
  },
  refundStatus: {
    requested: { labelKey: 'status.refund.requested', color: 'processing' },
    approved: { labelKey: 'status.refund.approved', color: 'gold' },
    confirmed: { labelKey: 'status.refund.confirmed', color: 'success' },
    rejected: { labelKey: 'status.refund.rejected', color: 'error' },
  },
  refundDestination: {
    guardian: { labelKey: 'status.party.guardian', color: 'default' },
    student: { labelKey: 'status.party.student', color: 'default' },
    school: { labelKey: 'status.party.school', color: 'default' },
  },
  withdrawalStatus: {
    pending: { labelKey: 'status.withdrawal.pending', color: 'warning' },
    approved: { labelKey: 'status.withdrawal.approved', color: 'success' },
    rejected: { labelKey: 'status.withdrawal.rejected', color: 'error' },
    cancelled: { labelKey: 'status.withdrawal.cancelled', color: 'default' },
  },
  templateStatus: {
    draft: { labelKey: 'status.template.draft', color: 'default' },
    published: { labelKey: 'status.template.published', color: 'success' },
  },
  teamStatus: {
    forming: { labelKey: 'status.team.forming', color: 'default' },
    submitted: { labelKey: 'status.team.submitted', color: 'processing' },
    confirmed: { labelKey: 'status.team.confirmed', color: 'success' },
    disbanded: { labelKey: 'status.team.disbanded', color: 'default' },
  },
  language: {
    en: { labelKey: 'locale.en', color: 'default' },
    'zh-TC': { labelKey: 'locale.zh-TC', color: 'default' },
    'zh-SC': { labelKey: 'locale.zh-SC', color: 'default' },
  },
  // S-UX3-4 — a booking's per-student state and a session's lifecycle state.
  bookingStatus: {
    booked: { labelKey: 'status.booking.booked', color: 'processing' },
    waitlisted: { labelKey: 'status.booking.waitlisted', color: 'warning' },
    attended: { labelKey: 'status.booking.attended', color: 'success' },
    no_show: { labelKey: 'status.booking.no_show', color: 'error' },
    cancelled: { labelKey: 'status.booking.cancelled', color: 'default' },
  },
  sessionStatus: {
    draft: { labelKey: 'status.session.draft', color: 'default' },
    published: { labelKey: 'status.session.published', color: 'processing' },
    full: { labelKey: 'status.session.full', color: 'warning' },
    in_progress: { labelKey: 'status.session.in_progress', color: 'gold' },
    completed: { labelKey: 'status.session.completed', color: 'success' },
    cancelled: { labelKey: 'status.session.cancelled', color: 'default' },
    rescheduled: { labelKey: 'status.session.rescheduled', color: 'default' },
  },
  // S-UX3-9 — enrolment lifecycle (guardian My Children).
  enrolmentStatus: {
    submitted: { labelKey: 'status.enrolment.submitted', color: 'processing' },
    pending_consent: { labelKey: 'status.enrolment.pending_consent', color: 'warning' },
    in_pool: { labelKey: 'status.enrolment.in_pool', color: 'processing' },
    teamed: { labelKey: 'status.enrolment.teamed', color: 'gold' },
    confirmed: { labelKey: 'status.enrolment.confirmed', color: 'success' },
    active: { labelKey: 'status.enrolment.active', color: 'success' },
    completed: { labelKey: 'status.enrolment.completed', color: 'success' },
    withdrawn: { labelKey: 'status.enrolment.withdrawn', color: 'default' },
    released: { labelKey: 'status.enrolment.released', color: 'default' },
  },
  // C7-RESULTS — the assessment result COARSENING (E1 embargo). Deliberately only two values: the raw assessment
  // status (draft|published|open|closed|graded|cancelled|released) is collapsed to `pending`/`released` BEFORE it
  // reaches this pill. The raw status must NEVER be a domain here — a family seeing `graded` would learn the
  // result exists and is being withheld, which is the embargo leaking one bit.
  // B7-MARKETPLACE — the catalogue's derived enrolment window (MarketplaceController: open|closed ONLY —
  // capacity/"Full" is deliberately NOT derivable by families, D-MKT-2). Labels reuse the existing
  // marketplace.status.* pair rather than minting a second vocabulary.
  catalogueStatus: {
    open: { labelKey: 'marketplace.status.open', color: 'success' },
    closed: { labelKey: 'marketplace.status.closed', color: 'default' },
  },
  // B3-GUA-CHILD — consent state as the FAMILY reads it. The values are the `cr_status_check` enum itself
  // (draft is never issued to a family, so it is not offered here); labels reuse the existing consent.status.*
  // set rather than minting parallel copy.
  consentState: {
    sent: { labelKey: 'consent.status.sent', color: 'warning' },
    viewed: { labelKey: 'consent.status.viewed', color: 'warning' },
    signed: { labelKey: 'consent.status.signed', color: 'success' },
    declined: { labelKey: 'consent.status.declined', color: 'error' },
    expired: { labelKey: 'consent.status.expired', color: 'error' },
    superseded: { labelKey: 'consent.status.superseded', color: 'default' },
    voided: { labelKey: 'consent.status.voided', color: 'default' },
  },
  // B1-STU-HOME — the student's consent-waiting pill (stu-home L444). ONE value: the fact is binary from
  // the student's side — their guardian has not signed yet. Not the consent state machine (the student is
  // not the signer and never sees declined/expired as an actionable state); a closed one-value domain keeps
  // the pill going through the single pill component rather than a hand-rolled <Tag>.
  consentWait: {
    waiting: { labelKey: 'studentHome.consentWaiting', color: 'processing' },
  },
  // S-TRACKER-1 — a stage gate as the family reads it. TWO values only: `stage_gates` records PASSES, so
  // the absence of a row is all we know. There is deliberately no 'in progress' and no 'locked' — the
  // server enforces no stage sequence, so either would state a rule the platform does not keep.
  stageGate: {
    passed: { labelKey: 'status.gate.passed', color: 'success' },
    pending: { labelKey: 'status.gate.pending', color: 'default' },
  },
  assessmentRelease: {
    pending: { labelKey: 'enrolSpace.results.pending', color: 'processing' },
    released: { labelKey: 'enrolSpace.results.released', color: 'success' },
  },
};

/** code → readable ("enrolment.submitted" → "Enrolment submitted"). Never i18n'd (open set). */
export function humanise(code: string): string {
  const s = code.replace(/[._]+/g, ' ').trim();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

// P0-3a §3.1 — the closed antd-preset set the registry already uses → the five ka-* pill states. gold is NOT
// a status color in v3 (gold = action accent only), so the four gold entries (covered_by_invoice, refund
// approved, session in_progress, enrolment teamed) — all transitional/in-flight — collapse to `pend`.
const KA_PILL: Record<string, 'ok' | 'warn' | 'danger' | 'pend' | 'neutral'> = {
  success: 'ok', warning: 'warn', error: 'danger', processing: 'pend', gold: 'pend', default: 'neutral',
};

export function StatusTag({ domain, value }: { domain: keyof typeof REGISTRY | string; value: string | null | undefined }) {
  const { t } = useTranslation();
  if (!value) return <>—</>;
  const entry = REGISTRY[domain]?.[value];
  // Visual-only re-skin: the registry (enum + i18n label key + preset) is UNTOUCHED; we map its preset to the
  // ka-* pill class at render. Props are identical, so all 32 call sites are unchanged. `bordered={false}` +
  // the ka-pill class deliver the filled tint (no dot/border/icon).
  const level = KA_PILL[entry?.color ?? 'default'] ?? 'neutral';
  return <Tag bordered={false} className={`ka-pill ka-pill--${level}`}>{entry ? t(entry.labelKey) : humanise(value)}</Tag>;
}

/**
 * Audit/auth action or entity_type on the AUDIT surfaces: humanised label AND the exact raw
 * code preserved (muted, plus a tooltip) — an auditor cross-referencing exports needs the byte.
 */
export function AuditCode({ code }: { code: string | null | undefined }) {
  if (!code) return <>—</>;
  return (
    <Tooltip title={code}>
      <span>
        {humanise(code)}{' '}
        <Typography.Text type="secondary" style={{ fontSize: 11 }}>
          {code}
        </Typography.Text>
      </span>
    </Tooltip>
  );
}

// S-UX2a — closed-set enum → i18n label + colour (StatusTag), and the open-set humaniser
// (AuditAction) for audit/auth action codes (D-UX2a.1). An unknown code never renders raw:
// StatusTag humanises it; AuditAction always humanises and preserves the exact code beside it.
import { Tag, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';

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
};

/** code → readable ("enrolment.submitted" → "Enrolment submitted"). Never i18n'd (open set). */
export function humanise(code: string): string {
  const s = code.replace(/[._]+/g, ' ').trim();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export function StatusTag({ domain, value }: { domain: keyof typeof REGISTRY | string; value: string | null | undefined }) {
  const { t } = useTranslation();
  if (!value) return <>—</>;
  const entry = REGISTRY[domain]?.[value];
  return <Tag color={entry?.color ?? 'default'}>{entry ? t(entry.labelKey) : humanise(value)}</Tag>;
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

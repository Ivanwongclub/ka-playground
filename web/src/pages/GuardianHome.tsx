// B2-GUA-HOME — the GUARDIAN persona home, composed to the prototype's `gua-home` (L701-729): an
// ACTION-REQUIRED LIST, nothing else. The file's own screen note (L2174) states the intent — "bold titles,
// bold deadlines — expires_at and payment_due_at as the loudest thing on the page" — which is why the hero,
// the gold CTAs and the stat trio the previous composition carried are all GONE (ruled B2, and Doc 2 §2's
// row was corrected in the same card: it had been describing this build rather than the block).
//
// CLIENT ONLY: no server change, no new read. THREE reads, unchanged in count (3 → 3) because every one is
// load-bearing — unlike B1, S-READ-2 cannot retire any of them:
//   • GET /api/consent-requests — the ONLY source of the consent request ID, which is the ceremony address
//     (/consents/{id}). The enrolment list's consent_status/consent_expires_at can DRESS a row but cannot
//     ADDRESS it; dropping this read would downgrade the drill to the list.
//   • GET /api/orders           — amounts, due dates, payer party. Deliberately absent from the enrolment
//     read (P-3: a student must never receive an amount), so it cannot move there.
//   • GET /api/enrolments       — the Children section (student_name grouped by student_id).
//
// Action-golds: 0. The block has no buttons at all — each row IS the affordance. Guardian amounts are
// permitted (P-3 is student-only) and the payment row carries its amount in the row TITLE, as the block does.
import { ChevronRight, CreditCard, FileSignature } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { ReactNode } from 'react';
import { Skeleton, Typography } from 'antd';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { personName } from '../display/names';
import { formatMoney } from '../display/money';
import { formatHktDayMonth } from '../display/date';
import { useResource, urgencyLevel, urgencyDays, urgencyLabel, URGENCY } from '@/ds2';
import type { UrgencyLevel } from '@/ds2';

interface ConsentRow { id: string; status: string; expires_at: string | null; student_name: string | null; programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null }
interface OrderRow { id: string; student_id: number; payer_party: string; status: string; total_amount_minor: number; currency: string; payment_due_at: string | null; student_name: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string }
interface EnrolRow { student_id: number; student_name: string | null }

const OPEN_CONSENT = new Set(['sent', 'viewed']);

const ts = (s: string | null): number => (s ? Date.parse(s.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')) : Number.POSITIVE_INFINITY);
function progName(r: { programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null }, locale: KaLocale): string {
  return (locale === 'zh-TC' ? r.programme_name_tc : locale === 'zh-SC' ? r.programme_name_sc : r.programme_name_en) || r.programme_name_en || '';
}
function initials(name: string): string {
  const parts = name.split(/\s+/).filter(Boolean);
  return (parts.length >= 2 ? parts[0][0] + parts[1][0] : name.slice(0, 2)).toUpperCase();
}

/** The deadline block: the loud fact bold and coloured by URGENCY LEVEL, the other fact small beneath it.
 *  The block hardcodes var(--warn) because its demo deadlines are always imminent; we colour by the built
 *  ladder instead (soon = pending blue → due = warning → overdue = danger, W1-GROUND), so a deadline sixty
 *  days out does not shout. `none` keeps the default text colour — still bold, still the loudest thing. */
const LEVEL_COLOR: Record<UrgencyLevel, string> = {
  none: 'var(--ka-fg)', soon: 'var(--ka-pending)', due: 'var(--ka-warning)', overdue: 'var(--ka-danger)',
};
function Deadline({ loud, quiet, level }: { loud: string; quiet: string; level: UrgencyLevel }) {
  return (
    <div style={{ textAlign: 'right', flex: 'none' }}>
      <b style={{ display: 'block', fontSize: 15.5, fontWeight: 700, color: LEVEL_COLOR[level] }}>{loud}</b>
      <small style={{ fontSize: 11, color: 'var(--ka-muted-fg)', textTransform: 'uppercase', letterSpacing: '.06em' }}>{quiet}</small>
    </div>
  );
}

/** One `.todo-row`: glyph · title (the subject) · who · deadline · chevron. The WHOLE ROW is one Link —
 *  the block has no inner controls, so nothing nests and the GUA-FIX objection does not arise. */
function TodoRow({ to, icon, title, who, deadline }: { to: string; icon: ReactNode; title: string; who: string; deadline: ReactNode }) {
  return (
    <Link to={to} style={{ color: 'inherit', display: 'block' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: '18px 0', borderBottom: '1px solid var(--ka-border)' }}>
        <span style={{ width: 44, height: 44, borderRadius: 12, background: 'var(--ka-muted)', display: 'grid', placeItems: 'center', flex: 'none', color: 'var(--ka-muted-fg)' }}>{icon}</span>
        <div style={{ flex: 1, minWidth: 0 }}>
          {/* keep-all: the title carries a money value ("Pay HK$2,500.00 for …") and a number split across
              two lines is unreadable. Wraps at spaces, never inside the amount. */}
          <Typography.Text strong style={{ display: 'block', fontSize: 16.5, wordBreak: 'keep-all' }}>{title}</Typography.Text>
          <Typography.Text type="secondary" style={{ fontSize: 13 }}>{who}</Typography.Text>
        </div>
        {deadline}
        <ChevronRight size={16} aria-hidden style={{ color: 'var(--ka-muted-fg)', flex: 'none' }} />
      </div>
    </Link>
  );
}

export function GuardianHome() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { identity } = useIdentity();

  const consents = useResource<{ data: ConsentRow[] }>('/api/consent-requests');
  const orders = useResource<{ data: OrderRow[] }>('/api/orders');
  const enrolments = useResource<{ data: EnrolRow[] }>('/api/enrolments');

  // Soonest deadline first. NO cap: the block renders the full list, bounded by family size (the previous
  // CONSENT_CAP/PAYMENT_CAP + "view all" overflow links were a build invention and went with the trio).
  const openConsents = (consents.data?.data ?? [])
    .filter((c) => OPEN_CONSENT.has(c.status))
    .sort((a, b) => ts(a.expires_at) - ts(b.expires_at));
  // Family-payable only — a school-settled order is the school's to remit, never the guardian's task.
  const payable = (orders.data?.data ?? [])
    .filter((o) => o.status === 'issued' && (o.payer_party === 'guardian' || o.payer_party === 'student'))
    .sort((a, b) => ts(a.payment_due_at) - ts(b.payment_due_at));

  // The Children section: one card per child, from the enrolments the guardian can already read.
  const byChild = new Map<number, { name: string; count: number }>();
  for (const e of enrolments.data?.data ?? []) {
    const prev = byChild.get(e.student_id);
    byChild.set(e.student_id, { name: e.student_name ?? '', count: (prev?.count ?? 0) + 1 });
  }
  const children = [...byChild.entries()].sort((a, b) => a[1].name.localeCompare(b[1].name));

  const needCount = openConsents.length + payable.length;
  const workLoading = consents.loading || orders.loading;

  return (
    <div data-density="product">
      <div style={{ fontSize: 12, letterSpacing: '.14em', textTransform: 'uppercase', fontWeight: 500, color: 'var(--ka-muted-fg)' }}>
        {t('guardianHome.eyebrow')}
      </div>
      <h2 style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 31, lineHeight: 1.15, margin: '4px 0 24px' }}>
        {personName(identity?.name ?? '')}
      </h2>

      {/* ACTION REQUIRED — the card exists iff a need exists (entitlement-iff). Zero needs ⇒ no card and no
          "all caught up" copy: the prototype has no such state, and an empty inbox needs no sentence. */}
      {workLoading ? (
        <Skeleton active paragraph={{ rows: 3 }} />
      ) : needCount > 0 && (
        <div style={{ background: 'var(--ka-card)', borderRadius: 'var(--ka-r-md)', borderLeft: '4px solid var(--ka-warning)', padding: '18px 20px 0' }}>
          <Typography.Title level={3} style={{ fontSize: 19, marginBottom: 4 }}>
            {t('guardianHome.actionRequired')} <span style={{ color: 'var(--ka-warning)' }}>· {needCount}</span>
          </Typography.Title>

          {openConsents.map((c) => {
            const level = urgencyLevel(c.expires_at, URGENCY.consent);
            return (
              <TodoRow
                key={c.id}
                to={`/consents/${c.id}`}
                icon={<FileSignature size={20} aria-hidden />}
                title={t('guardianHome.signConsentFor', { child: personName(c.student_name ?? '') })}
                who={progName(c, locale)}
                // NO deadline ⇒ NO deadline block. urgencyDays(null) is 0, so urgencyLabel would read "Due
                // today" beside an em-dash date — a fabricated urgency on a consent that simply has no
                // expiry. Omit rather than invent (caught in the built shot, not in review).
                deadline={c.expires_at ? <Deadline level={level} loud={urgencyLabel(level, urgencyDays(c.expires_at), t)} quiet={t('guardianHome.expiresOn', { date: formatHktDayMonth(c.expires_at, locale) })} /> : null}
              />
            );
          })}

          {payable.map((o) => {
            const level = urgencyLevel(o.payment_due_at, URGENCY.payment);
            return (
              <TodoRow
                key={o.id}
                to="/my/payments"
                icon={<CreditCard size={20} aria-hidden />}
                title={t('guardianHome.payFor', { amount: formatMoney(o.total_amount_minor, o.currency, locale), child: personName(o.student_name ?? '') })}
                // programme only — the block's "· fee + materials" needs order_lines, which the orders LIST
                // read does not select (OrdersController:29). Omitted, not approximated. FLAG.
                who={progName(o, locale)}
                deadline={o.payment_due_at ? <Deadline level={level} loud={t('guardianHome.dueOn', { date: formatHktDayMonth(o.payment_due_at, locale) })} quiet={urgencyLabel(level, urgencyDays(o.payment_due_at), t)} /> : null}
              />
            );
          })}
        </div>
      )}

      <Typography.Title level={3} style={{ fontSize: 15.5, fontWeight: 700, margin: '18px 0 12px' }}>{t('guardianHome.children')}</Typography.Title>
      {enrolments.loading ? <Skeleton active paragraph={{ rows: 2 }} /> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {children.map(([id, c]) => (
            <Link key={id} to={`/my/children/${id}`} style={{ color: 'inherit', display: 'block' }}>
              <div style={{ background: 'var(--ka-card)', borderRadius: 'var(--ka-r-md)', padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 12 }}>
                <span style={{ width: 38, height: 38, borderRadius: '50%', background: 'var(--ka-muted)', display: 'grid', placeItems: 'center', fontSize: 12, fontWeight: 600, color: 'var(--ka-muted-fg)', flex: 'none' }}>
                  {initials(personName(c.name))}
                </span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <Typography.Text strong style={{ display: 'block', fontSize: 14 }}>{personName(c.name)}</Typography.Text>
                  <Typography.Text type="secondary">{c.count} {t('selfService.enrolments', { count: c.count })}</Typography.Text>
                </div>
                <ChevronRight size={16} aria-hidden style={{ color: 'var(--ka-muted-fg)', flex: 'none' }} />
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}

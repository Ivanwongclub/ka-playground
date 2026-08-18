// C2-LIST — the STUDENT "Programmes" list: one GlanceCard per enrolment (§3.2 image band → status pill →
// segbar → label/value rows). The card is the front door — the whole card DRILLS into the scoped programme
// space /enrolments/:enrolmentId (C1-SHELL, D-1 keyed on enrolment_id).
//
// INTERIM COMPOSITION — the target shape is a SINGLE enrolment-list read that carries banner + consent + fee
// fields (audit C4, the missing enrolment-detail endpoint). No such endpoint exists, so this surface merges
// three more RLS-shaped reads CLIENT-SIDE by programme_id. This fan-out is the interim by construction; when
// the enrolment read carries these fields, delete the extra reads. Do not mistake this for the target shape.
//   Requests (all parallel, matched client-side by programme_id):
//     1. GET /api/enrolments        — the caller's own enrolments (identity · status · segbar)
//     2. GET /api/programmes        — catalogue banner_url for the image band (§c interim; brand-gradient fallback)
//     3. GET /api/consent-requests  — consent status per programme (INFORMATIVE for the student — the guardian signs)
//     4. GET /api/orders            — fee status per programme (rendered IFF the student's RLS returns a match)
// DEFERRED (C2-LIST ruling #4, entitlement-iff): Team needs an N+1 roster-probe (/api/teams lists all lobby
// teams, not the student's); Next-session's read returns team_id not programme_id, so per-enrolment attribution
// needs a server change. Tracker/Results are deferred to their own card. Omitted rows are never placeholdered.
import { Space, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import type { KaLocale } from '../i18n';
import { GlanceCard, StatusTag, useResource, DataBoundary } from '@/ds2';
import type { SegItem, GlanceRow } from '@/ds2';
import { programmeName } from '../display/names';
import { formatHkt } from '../display/date';

const { Title } = Typography;

interface Enrolment { id: string; programme_id: number; status: string; programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null }
interface Catalogue { id: number; banner_url: string | null }
interface ConsentReq { programme_id: number; status: string }
// Student read: status + due only — the amount fields (total_amount_minor/currency) are DELIBERATELY not typed
// here so the figure cannot be rendered on this surface (P-3 / B-18; see rowsFor).
interface Order { programme_id: number; status: string; payment_due_at: string | null }

// 7 real enrolment states → 5 display segments (C2-LIST ruling #1): pending_consent folds under Submitted,
// completed under Active (the terminal end); withdrawn/released carry NO segbar (the status pill says it).
const SEG: { key: string; lbl: string }[] = [
  { key: 'submitted', lbl: 'submitted' },
  { key: 'in_pool', lbl: 'pool' },
  { key: 'teamed', lbl: 'teamed' },
  { key: 'confirmed', lbl: 'confirmed' },
  { key: 'active', lbl: 'active' },
];
const FOLD: Record<string, number> = { submitted: 0, pending_consent: 0, in_pool: 1, teamed: 2, confirmed: 3, active: 4, completed: 4 };
const TERMINAL_BAD = ['withdrawn', 'released'];

function segFor(status: string, t: (k: string) => string): SegItem[] | undefined {
  if (TERMINAL_BAD.includes(status)) return undefined; // no segbar — the status pill carries a terminal-bad state
  const cur = FOLD[status] ?? 0;
  const done = status === 'completed'; // terminal end — the whole journey reads as done
  return SEG.map((s, i) => ({
    label: t(`enrolCard.seg.${s.lbl}`),
    state: done || i < cur ? 'done' : i === cur ? 'current' : 'todo',
  }));
}

// The student's consent + fee rows are INFORMATIVE — a student neither signs their own consent (the guardian
// does) nor pays (§3.1: informative values are right-aligned plain text, never a pill and never an action).
function rowsFor(consent: ConsentReq | undefined, order: Order | undefined, locale: KaLocale, t: (k: string) => string): GlanceRow[] {
  const rows: GlanceRow[] = [];
  if (consent) {
    const txt = ['sent', 'viewed'].includes(consent.status) ? t('studentHome.consentWaiting')
      : consent.status === 'signed' ? t('selfService.consentMet')
      : t(`consent.status.${consent.status}`);
    rows.push({ label: t('enrolCard.consent'), value: { text: txt } });
  }
  if (order) {
    // STATUS ONLY — a student never sees the fee FIGURE or currency (owner answer 11; backlog P-3; audit S1
    // DM-5). orders_read still exposes amounts server-side, so this UI restraint is the sole thing preserving
    // the rule until the B-18 orders-read narrowing lands. DO NOT re-add formatMoney/total_amount_minor here.
    const txt = order.status === 'issued'
      ? (order.payment_due_at ? `${t('enrolCard.feeDue')} ${formatHkt(order.payment_due_at, locale)}` : t('enrolCard.feeDue'))
      : t(`status.order.${order.status}`);
    rows.push({ label: t('enrolCard.fees'), value: { text: txt } });
  }
  return rows;
}

export function StudentProgrammes() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const navigate = useNavigate();
  const enr = useResource<{ data: Enrolment[] }>('/api/enrolments');
  const cat = useResource<{ data: Catalogue[] }>('/api/programmes');
  const con = useResource<{ data: ConsentReq[] }>('/api/consent-requests');
  const ord = useResource<{ data: Order[] }>('/api/orders');

  const enrolments = enr.data?.data ?? [];
  const bannerBy = new Map((cat.data?.data ?? []).map((c) => [c.id, c.banner_url]));
  const consentBy = new Map((con.data?.data ?? []).map((c) => [c.programme_id, c]));
  const orderBy = new Map((ord.data?.data ?? []).map((o) => [o.programme_id, o]));

  return (
    <div data-density="product">
      <Space direction="vertical" size="large" style={{ width: '100%' }}>
        <Title level={3} style={{ marginBottom: 0 }}>{t('enrolCard.title')}</Title>
        <DataBoundary loading={enr.loading} error={enr.error} empty={enrolments.length === 0}>
          <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            {enrolments.map((e) => {
              const name = programmeName(e, locale);
              const banner = bannerBy.get(e.programme_id) ?? null;
              return (
                <GlanceCard
                  key={e.id}
                  // banner_url from the catalogue merge when present; else a deterministically-failing src makes
                  // HeroBanner fall back to the §3.2 brand gradient — no broken image, no new fetch (§c interim).
                  image={{ src: banner || 'data:,', alt: name }}
                  imageFallback={<div style={{ width: '100%', height: '100%', background: 'linear-gradient(135deg, var(--ka-cat-stem), var(--ka-card))' }} />}
                  title={name}
                  status={<StatusTag domain="enrolmentStatus" value={e.status} />}
                  segments={segFor(e.status, t)}
                  rows={rowsFor(consentBy.get(e.programme_id), orderBy.get(e.programme_id), locale, t)}
                  onClick={() => navigate(`/enrolments/${e.id}`)}
                />
              );
            })}
          </Space>
        </DataBoundary>
      </Space>
    </div>
  );
}

// C1-SHELL / C4-TABS-1 — the family SCOPED PROGRAMME SPACE (D-1: keyed on ENROLMENT_ID; the guardian reuses it
// per child × programme, and the staff Enrolment record C4 is the same object in the other grammar). C4-TABS-1
// fills the JOURNEY tab (stepper + what-next). Team · Sessions · Tracker · Results stay EMPTY — see below.
//
// BLOCKED, NOT thin-by-choice (recorded per the C4-TABS-1 ruling): the Sessions tab and the Journey stat-tile
// row (Team / Next session / Consent) are OMITTED because the reads do not exist —
//   • the session read (SessionReadController) carries team_id, NOT programme_id, so this enrolment's sessions
//     cannot be scoped without a server change (an unscoped list in a scoped space is worse than none);
//   • the enrolment read carries no per-transition timestamps, so the stepper's dates are omitted (never faked
//     from created_at);
//   • the Team tile needs the N+1 roster-probe (over budget) and the Next-session tile needs the scoped session
//     read; the Consent tile is already surfaced elsewhere (a 4th rendering adds nothing).
// ONE server change — programme_id on the session read — unblocks the Sessions tab AND the Next-session tile
// AND (with an enrolment-detail read) most of what C2-LIST dropped. That read is the highest-value backlog item.
//
// ENTITLEMENT: there is no enrolment-detail endpoint (audit C4) — INTERIM, the space reads the RLS-scoped
// enrolment LIST and filters client-side by id. An enrolment the viewer cannot read is simply ABSENT from the
// list → NotFound (Proposal A1 deep-link corollary). Both personas (student · guardian) reach it via the same read.
import { useParams } from 'react-router-dom';
import { Skeleton, Tabs } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource } from '../api/useResource';
import { programmeName } from '../display/names';
import { StatusTag } from '../display/status';
import { ProgrammeBandHeader, EmptyState, JourneyStepper } from '@/ds2';
import { NotFound } from './NotFound';

interface EnrolRow {
  id: string;
  status: string;
  programme_name_en: string | null;
  programme_name_tc: string | null;
  programme_name_sc: string | null;
}

// spec C4 order — FIXED: Journey · Team · Sessions · Tracker · Results.
const TABS = ['journey', 'team', 'sessions', 'tracker', 'results'] as const;

// C4-TABS-1 — the SAME 7-real-state → 5-display-state model as C2-LIST (do not invent a second one).
// pending_consent folds under Submitted; completed under Active (terminal end); withdrawn/released carry NO
// stepper (the band's status pill says it). SEG entries are the enrolCard.seg.* label keys.
const SEG = ['submitted', 'pool', 'teamed', 'confirmed', 'active'];
const FOLD: Record<string, number> = { submitted: 0, pending_consent: 0, in_pool: 1, teamed: 2, confirmed: 3, active: 4, completed: 4 };
const TERMINAL_BAD = ['withdrawn', 'released'];
// status → whatNext string (§3.4's one sanctioned prose slot). Terminal-bad has none.
const WHATNEXT: Record<string, string> = { submitted: 'submitted', pending_consent: 'submitted', in_pool: 'in_pool', teamed: 'teamed', confirmed: 'confirmed', active: 'active', completed: 'completed' };

// JOURNEY tab — the §3.4 stepper (fold-derived) + what-next. NO stat-tile row and NO per-knot dates (blocked,
// above). Terminal-bad → the status pill only, no stepper.
function JourneyPanel({ status, locale }: { status: string; locale: KaLocale }) {
  const { t } = useTranslation();
  if (TERMINAL_BAD.includes(status)) return <StatusTag domain="enrolmentStatus" value={status} />;
  const steps = SEG.map((lbl) => ({ title: t(`enrolCard.seg.${lbl}`) }));
  const current = status === 'completed' ? SEG.length : (FOLD[status] ?? 0);
  const wn = WHATNEXT[status];
  return (
    <JourneyStepper
      steps={steps}
      current={current}
      locale={locale}
      whatNext={wn ? t(`enrolSpace.whatNext.${wn}`) : undefined}
      whatNextLabel={t('enrolSpace.whatNextLabel')}
    />
  );
}

export function EnrolmentSpace() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { enrolmentId } = useParams();
  const { data, loading } = useResource<{ data: EnrolRow[] }>('/api/enrolments');

  if (loading) return <Skeleton active paragraph={{ rows: 4 }} />;
  const enrol = (data?.data ?? []).find((e) => e.id === enrolmentId);
  if (!enrol) return <NotFound />; // absent from the viewer's RLS-scoped list ⇒ not entitled (A1)

  const name = programmeName(enrol, locale);
  return (
    <div style={{ maxWidth: 900 }}>
      <ProgrammeBandHeader
        // The enrolment LIST read carries no programme image (interim, audit C4). A deterministically-failing src
        // makes HeroBanner fall back to the §3.2 brand gradient — no new fetch, no DS2 primitive change (FLAG b).
        image={{ src: 'data:,', alt: name }}
        imageFallback={<div style={{ width: '100%', height: '100%', background: 'linear-gradient(135deg, var(--ka-cat-stem), var(--ka-card))' }} />}
        name={name}
        status={<StatusTag domain="enrolmentStatus" value={enrol.status} />}
      />
      <Tabs
        style={{ marginTop: 16 }}
        items={TABS.map((k) => ({
          key: k,
          label: t(`enrolSpace.tab.${k}`),
          // C4-TABS-1: Journey is filled. The still-empty tabs render an ICON-ONLY empty — no gap-explaining
          // copy ("empty is empty", C4-TABS-1 ruling; the C1-SHELL "arrives in a later sprint" caption was
          // instructional and is removed). Client-side tab switching (no route change) is kept — one record.
          children: k === 'journey'
            ? <JourneyPanel status={enrol.status} locale={locale} />
            : <EmptyState message={null} />,
        }))}
      />
    </div>
  );
}

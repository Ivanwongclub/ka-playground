// C1-SHELL — the family SCOPED PROGRAMME SPACE (D-1: keyed on ENROLMENT_ID; the guardian reuses it per
// child × programme, and the staff Enrolment record C4 is the same object in the other grammar). SKELETON ONLY:
// the programme band header + a LABELLED but EMPTY tab strip (Journey · Team · Sessions · Tracker · Results, the
// fixed spec-C4 order). NO tab content — that is the next card.
//
// ENTITLEMENT: there is no enrolment-detail endpoint (audit C4) — INTERIM, the skeleton reads the RLS-scoped
// enrolment LIST and filters client-side by id. An enrolment the viewer cannot read is simply ABSENT from the
// list → NotFound (Proposal A1 deep-link corollary, same shape as StudentTeam's P0-SAFE-1 guard). Replace this
// list-filter with a real enrolment-detail read when one exists.
import { useParams } from 'react-router-dom';
import { Skeleton, Tabs } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource } from '../api/useResource';
import { programmeName } from '../display/names';
import { StatusTag } from '../display/status';
import { ProgrammeBandHeader, EmptyState } from '@/ds2';
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
          // C1-SHELL: labelled EMPTY panel — the strip is the skeleton's point; tab CONTENT is the next card.
          children: <EmptyState message={t('empty.caption')} />,
        }))}
      />
    </div>
  );
}

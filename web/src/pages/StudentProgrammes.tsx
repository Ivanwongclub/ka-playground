// C2-LIST / C6-CONSUME — the STUDENT "Programmes" list: one GlanceCard per enrolment (§3.2 image band →
// status pill → segbar → label/value rows), the front door that DRILLS into /enrolments/:enrolmentId.
//
// ONE read now (C6-CONSUME): GET /api/enrolments — WIDENED (S-READ-2) to carry banner_url · team_name ·
// consent_status · next_session · … . The catalogue-merge fetch AND the separate consent-requests fetch are
// GONE (3 reads → 1). No Fees row: fees are the GUARDIAN's object; a student never receives an amount (P-3/B-18).
//
// THE ROW SET IS EMERGENT, NOT A TABLE OF STATES (C6 ruling): each row renders IFF its computed value is
// non-empty — entitlement-iff, one level down. Do NOT convert this into per-state branches; the confirmed vs
// in-pool difference FALLS OUT of which values exist (signed consent → no Consent row; teamed → team name;
// in-pool → "Not yet" + Find-a-team; no session → no Next-session row). Tracker/Results have no read → their
// value is always empty → they never render (DEFERRED — no tracker/assessment read; not faked). Programme term
// ("Sep – Dec 2026") is unmodelled (MODEL-LACKS-THE-FIELD) → no dates row.
import { Button, Space, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import type { MouseEvent } from 'react';
import type { KaLocale } from '../i18n';
import { GlanceCard, StatusTag, useResource, DataBoundary } from '@/ds2';
import type { GlanceRow } from '@/ds2';
import { segItems } from '../display/enrolmentJourney';
import { programmeName } from '../display/names';
import { formatHkt } from '../display/date';

const { Title, Text } = Typography;

interface Enrolment {
  id: string; programme_id: number; status: string;
  programme_name_en: string | null; programme_name_tc: string | null; programme_name_sc: string | null;
  banner_url: string | null; team_name: string | null;
  consent_status: string | null; consent_expires_at: string | null;
  next_session_title: string | null; next_session_starts_at: string | null;
  // NB: no amount field is typed here — a student must never receive one (P-3/B-18).
}

function rowsFor(e: Enrolment, locale: KaLocale, t: (k: string) => string, stop: (ev: MouseEvent) => void): GlanceRow[] {
  const rows: GlanceRow[] = [];
  // Consent — INFORMATIVE only: the student cannot sign (the guardian does). Renders while unresolved; a signed
  // consent leaves nothing to say → no row. "Remind" is DOMAIN-UNBUILT (D6/B-19) — omitted.
  if (e.consent_status === 'sent' || e.consent_status === 'viewed') {
    rows.push({ label: t('enrolCard.consent'), value: { text: t('studentHome.consentWaiting') } });
  }
  // Team — the name once teamed/confirmed; "Not yet" + the card's ONE gold "Find a team" while in the pool;
  // nothing before the pool. member_count is D-7 PROTOTYPE-WRONG (a new visibility path) — never rendered.
  if (e.team_name) {
    rows.push({ label: t('enrolCard.team'), value: { text: e.team_name } });
  } else if (e.status === 'in_pool') {
    rows.push({ label: t('enrolCard.team'), value: { action: (
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
        <Text type="secondary">{t('enrolCard.notYet')}</Text>
        <Link to="/my/team" onClick={stop}><Button type="primary" size="small" className="ka-cta">{t('enrolCard.findTeam')}</Button></Link>
      </span>
    ) } });
  }
  // Next session — title + start.
  if (e.next_session_title) {
    rows.push({ label: t('enrolCard.nextSession'), value: { text: `${e.next_session_title} · ${formatHkt(e.next_session_starts_at ?? '', locale)}` } });
  }
  return rows;
}

export function StudentProgrammes() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const navigate = useNavigate();
  const enr = useResource<{ data: Enrolment[] }>('/api/enrolments');
  const enrolments = enr.data?.data ?? [];
  const stop = (ev: MouseEvent) => ev.stopPropagation();

  return (
    <div data-density="product">
      <Space direction="vertical" size="large" style={{ width: '100%' }}>
        <Title level={3} style={{ marginBottom: 0 }}>{t('enrolCard.title')}</Title>
        <DataBoundary loading={enr.loading} error={enr.error} empty={enrolments.length === 0}>
          <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            {enrolments.map((e) => {
              const name = programmeName(e, locale);
              return (
                <GlanceCard
                  key={e.id}
                  // banner_url now comes from the enrolment read itself (S-READ-2); null → a deterministically-
                  // failing src makes HeroBanner fall back to the §3.2 brand gradient. The catalogue hack is gone.
                  image={{ src: e.banner_url || 'data:,', alt: name }}
                  imageFallback={<div style={{ width: '100%', height: '100%', background: 'linear-gradient(135deg, var(--ka-cat-stem), var(--ka-card))' }} />}
                  title={name}
                  status={<StatusTag domain="enrolmentStatus" value={e.status} />}
                  segments={segItems(e.status, t)}
                  rows={rowsFor(e, locale, t, stop)}
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

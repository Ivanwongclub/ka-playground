// KAP-MKT-1 — the programme MARKETPLACE. A DS2-native card grid over the ANONYMOUS-safe catalogue
// (GET /programmes: published + marketing-complete only; no PII, no capacity, no enrolled flag — R-4).
// The R-2 button state is DERIVED, never a client flag: from the catalogue's open/closed status + the
// caller's OWN /api/enrolments (a student sees their own, a guardian their children's — enr_read).
// The Enroll PRESS is a GUARDIAN action reusing POST /my/enrolments → EnrolmentService::create verbatim
// (guardian-led per OD-23/OD-27; lands at Submitted → the guardian's consent task, ceremony untouched).
// A student sees the full browse + status but no Enroll button — an honest "your guardian enrols you".
//
// B7-MARKETPLACE — restyled to the prototype's stu-explore card grammar (L583-618): mp-hero → filter chips →
// cards of band+name / term·ages / state chip. EXPLORE ONLY: stu-progdet is DEFERRED (ruled), because
// MarketplaceController::show returns the SAME payload as the list and About/Schedule/Venue are not modelled
// at all — a detail page would be a hero plus five stage labels, duplicating the card just tapped.
//
// NO PRICE. The block bolds a fee on every card; `fee_items_read` has NO family and NO anonymous arm
// (system OR academy_admin with finance|configuration|operations|audit_read), and the S02B policy comment
// ties that to the unresolved S04A consumer clause. So a price here is MODEL-FORBIDS as the policy stands,
// not a read-widen — the cards carry term · ages · state and no fee line. Never faked. FLAG (owner ruling).
//
// NO DRILL for an un-enrolled card: with progdet deferred there is no family-facing programme-detail route
// (main.tsx has none), so the block's "View ›" would point nowhere. It is omitted rather than rendered dead.
// An ENROLLED card keeps its real drill — "Open ›" → /enrolments/{id}, the scoped space.
//
// "Coming soon" filter OMITTED: it needs `enrolment_opens_at`, which the read derives `status` from but does
// not expose. The only proxy (current ∧ closed) also catches ENDED programmes, so the chip would mislabel
// them as forthcoming. One-field RW; flagged, not approximated.
import { useState } from 'react';
import { App, Button, Col, Modal, Row, Select, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { isGuardianActor } from '../nav';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { Link } from 'react-router-dom';
import { personName } from '../display/names';
import { asset } from '../assets';
import { formatHktDayMonth } from '../display/date';
import { StatusTag } from '../display/status';
import { SubPanel, EmptyState, HeroBanner } from '@/ds2';

const { Title, Text, Paragraph } = Typography;

interface Tri { en: string; tc: string; sc: string }
interface ProgrammeCard {
  id: number; code: string; name_en: string; name_tc: string; name_sc: string;
  phase: string; status: 'open' | 'closed'; brand_color: string; banner_url: string | null;
  tagline: Tri; category: Tri; age_range: Tri; duration: Tri;
  starts_on: string | null; enrolment_closes_on: string | null;
}
interface EnrolRow { id: string; student_id: number; programme_id: number; status: string; student_name: string | null }
type Filter = 'all' | 'open' | 'enrolled';

// "Any active record" (R-2) — a live enrolment (anything that is not a terminal exit).
const TERMINAL = new Set(['withdrawn', 'released']);
function triOf(t: Tri | undefined, locale: KaLocale): string {
  if (!t) return '';
  return (locale === 'zh-TC' ? t.tc : locale === 'zh-SC' ? t.sc : t.en) || t.en;
}
function progName(c: ProgrammeCard, locale: KaLocale): string {
  return (locale === 'zh-TC' ? c.name_tc : locale === 'zh-SC' ? c.name_sc : c.name_en) || c.name_en;
}

export function Marketplace() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { has } = useIdentity();
  const { message } = App.useApp();
  const isGuardian = isGuardianActor(has); // guardian-exclusive (capability_forbidden bars every cap group)

  const catalogue = useResource<{ data: ProgrammeCard[] }>('/api/programmes');
  const enrolments = useResource<{ data: EnrolRow[] }>('/api/enrolments'); // the caller's OWN (R-2/R-4)
  const [filter, setFilter] = useState<Filter>('all');
  const [enrolFor, setEnrolFor] = useState<ProgrammeCard | null>(null);
  const [pick, setPick] = useState<number | undefined>(undefined);

  const rows = enrolments.data?.data ?? [];
  // Programme ids the caller (or their children) already hold a LIVE enrolment in.
  const enrolled = new Set(rows.filter((e) => !TERMINAL.has(e.status)).map((e) => e.programme_id));
  // A guardian's distinct children (from their own enrolments); used by the enrol picker.
  const children = Array.from(new Map(rows.map((e) => [e.student_id, e.student_name])).entries()).map(([id, name]) => ({ id, name }));

  const submit = async () => {
    if (!enrolFor || pick === undefined) return;
    const r = await mutate('/api/my/enrolments', { programme_id: enrolFor.id, student_id: pick });
    setEnrolFor(null);
    setPick(undefined);
    if (r.ok) { void message.success(t('marketplace.enrolled')); enrolments.reload(); }
    else void message.error(r.message ?? t('mutate.failed')); // closed/duplicate surfaced, server-authoritative
  };

  // The caller's live enrolments in a programme. A guardian can hold SEVERAL (one per child), which is why
  // the drill below is only offered when exactly one exists — "Open ›" must address one enrolment, not guess.
  const liveFor = (id: number) => rows.filter((e) => e.programme_id === id && !TERMINAL.has(e.status));

  // The card's state chip: enrolled beats open/closed, because it is the caller's own fact.
  const chip = (c: ProgrammeCard) => {
    const live = liveFor(c.id);
    if (live.length === 1) {
      return (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <span className="ant-tag ka-pill ka-pill--ok">{t('marketplace.enrolledState')}</span>
          <StatusTag domain="enrolmentStatus" value={live[0].status} />
        </span>
      );
    }
    // >1 (a guardian with several children in one programme): the fact is "enrolled", but no single status
    // is true of the card — the per-child statuses live on Children, not here.
    if (live.length > 1) return <span className="ant-tag ka-pill ka-pill--ok">{t('marketplace.enrolledState')}</span>;
    return <StatusTag domain="catalogueStatus" value={c.status} />;
  };

  // The card's ONE affordance, right-aligned opposite the chip.
  const action = (c: ProgrammeCard) => {
    const live = liveFor(c.id);
    // Enrolled and unambiguous → the real drill, into the scoped space.
    // the block's `.link-name` is GOLD text (L169), not antd's link blue. Styled from the token rather than
    // a class name — there is no .ka-linkname in the codebase (checked; it would have rendered blue).
    if (live.length === 1) return <Link to={`/enrolments/${live[0].id}`} style={{ color: 'var(--ka-gold)' }}>{t('marketplace.open')}</Link>;
    if (live.length > 1) return null;                 // ambiguous target — Children is the entry, not this card
    if (c.status === 'closed') return null;           // nothing to do on a closed programme; the chip says so
    // NOT gold. The block gives the surface its ONE gold to the hero button (L588) and gives its cards a
    // chip + `.link-name` text, no button at all. A per-card gold would also MULTIPLY — five open programmes,
    // five golds — so the affordance stays, the gold does not (≤1 action-gold, standing rule).
    if (isGuardian) return <Button size="small" onClick={() => { setEnrolFor(c); setPick(undefined); }}>{t('marketplace.enrol')}</Button>;
    // Student on an open, not-enrolled programme — honest, never a dead button (guardian-led enrolment).
    return <Text type="secondary" style={{ fontSize: 12 }}>{t('marketplace.guardianEnrols')}</Text>;
  };

  // "From {start} · {duration}" — the block's "Feb – Jun 2027" needs an END date, and programmes.ends_at is
  // still writerless (AUDIT-2 A-1). The start is real and the marketing duration is real; a fabricated end
  // month would not be. FLAG.
  const term = (c: ProgrammeCard) => {
    const start = c.starts_on ? formatHktDayMonth(c.starts_on, locale) : null;
    const dur = triOf(c.duration, locale);
    return [start ? t('marketplace.termFrom', { start }) : null, dur].filter(Boolean).join(' · ');
  };

  const visible = (catalogue.data?.data ?? []).filter((c) =>
    filter === 'open' ? c.status === 'open' && liveFor(c.id).length === 0
      : filter === 'enrolled' ? liveFor(c.id).length > 0
        : true);

  return (
    <div style={{ maxWidth: 1100 }} data-density="product">
      {/* mp-hero (L584): imagery + scrim, headline, lead, and a gold button that SETS THE FILTER — it is
          not navigation. The surface's ONE action-gold.
          HeroBanner, not a hand-rolled background: the block's scrim is HORIZONTAL (dark to 25%, clear by
          60%), which is legible across a desktop span2 card and NOT at 390px — the lead landed on the bright
          part of the photo and could not be read (caught in the built shot). The DS2 scrim is the binding
          treatment and carries the image-fail fallback the hand-rolled div did not. Height is the house
          --ka-hero-band (180px), not the block's 230px: same ruling as B1R. */}
      {/* HeroBanner takes no style prop (deliberately — DS2 owns its box), so the zone gap below it is the
          wrapper's, the same way B1 spaces its hero. */}
      <div style={{ marginBottom: 'var(--ka-zone-gap)' }}>
      <HeroBanner image={{ src: asset('programmes/heroes/hero-sc1.jpg'), alt: '' }} height="band">
        <Title level={2} style={{ fontSize: 26, marginBottom: 6 }}>{t('marketplace.heroTitle')}</Title>
        <Paragraph style={{ fontSize: 13.5, maxWidth: '52ch', marginBottom: 0 }}>{t('marketplace.heroLead')}</Paragraph>
        <Button type="primary" className="ka-cta" style={{ marginTop: 14 }} onClick={() => setFilter('open')}>{t('marketplace.browseOpen')}</Button>
      </HeroBanner>
      </div>

      {/* Filter chips (L591). The ACTIVE chip is gold — a SELECTION state, not an action gold. "Coming soon"
          is absent: see the header note (enrolment_opens_at is not served). */}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', margin: '2px 0 16px' }} role="group" aria-label={t('marketplace.title')}>
        {(['all', 'open', 'enrolled'] as Filter[]).map((f) => (
          <button
            key={f}
            type="button"
            onClick={() => setFilter(f)}
            aria-pressed={filter === f}
            style={{
              cursor: 'pointer', borderRadius: 999, padding: '7px 14px', fontWeight: filter === f ? 600 : 500,
              border: '1px solid transparent',
              background: filter === f ? 'var(--ka-gold)' : 'var(--ka-muted)',
              // #241a12 is DS2's on-gold value, used raw at atoms.css:7/12/15 and structure.css (the done
              // step's check). There is no --ka-on-gold token; referencing one would have silently fallen
              // back. Same stale-literal class as the §D sweep — FLAGGED, not invented here.
              color: filter === f ? '#241a12' : 'var(--ka-muted-fg)',
            }}
          >
            {t(`marketplace.filter.${f}`)}
          </button>
        ))}
      </div>
      <DataBoundary loading={catalogue.loading} error={catalogue.error} empty={visible.length === 0}>
        <Row gutter={[16, 16]} align="stretch">
          {visible.map((c) => (
            <Col key={c.id} xs={24} sm={12} md={8}>
              <SubPanel tone="neutral">
                {/* 150px band with the NAME ON IT (L595), as the enrolment cards do. The scan-clean banner;
                    brand_color shows through if it is absent or fails — never a broken image. */}
                <div style={{ position: 'relative', height: 150, borderRadius: 8, overflow: 'hidden', background: c.brand_color || 'var(--ka-muted)', marginBottom: 12, display: 'flex', alignItems: 'flex-end' }}>
                  {c.banner_url && <img src={c.banner_url} alt="" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />}
                  <span style={{ position: 'relative', padding: '12px 16px', color: '#fff', fontWeight: 700, fontSize: 15.5, textShadow: '0 1px 6px rgba(0,0,0,.5)' }}>{progName(c, locale)}</span>
                </div>
                {/* term · ages. The block bolds a PRICE at the right of this row — omitted, not faked. */}
                <Text type="secondary">{[term(c), triOf(c.age_range, locale)].filter(Boolean).join(' · ')}</Text>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginTop: 10 }}>
                  {chip(c)}
                  {action(c)}
                </div>
              </SubPanel>
            </Col>
          ))}
        </Row>
      </DataBoundary>

      {/* Guardian enrol — pick a child (distinct children from the caller's enrolments; already-enrolled excluded). */}
      <Modal
        open={enrolFor !== null}
        title={enrolFor ? t('marketplace.enrolTitle', { programme: progName(enrolFor, locale) }) : ''}
        okText={t('marketplace.enrol')}
        cancelText={t('common.cancel')}
        okButtonProps={{ disabled: pick === undefined }}
        onOk={() => void submit()}
        onCancel={() => { setEnrolFor(null); setPick(undefined); }}
      >
        {enrolFor && (
          children.length === 0
            ? <EmptyState size="inline" message={t('marketplace.noChildren')} />
            : (
              <>
                <Paragraph type="secondary">{t('marketplace.enrolBody')}</Paragraph>
                <Select
                  style={{ width: '100%' }}
                  placeholder={t('marketplace.pickChild')}
                  value={pick}
                  onChange={setPick}
                  options={children.map((ch) => ({ value: ch.id, label: personName(ch.name), disabled: enrolled.has(enrolFor.id) && rows.some((e) => e.student_id === ch.id && e.programme_id === enrolFor.id && !TERMINAL.has(e.status)) }))}
                />
              </>
            )
        )}
      </Modal>
    </div>
  );
}

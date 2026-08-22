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
// PRICE — B9 consumes S-READ-3 item 2. The B7 note this replaces said MODEL-FORBIDS, and it was true then:
// `fee_items_read` has no family and no anonymous arm. S-READ-3 did not widen that policy — it added
// `fee_total_minor` + `currency` to the catalogue read, summed behind ONE registered elevation, and ONLY for
// an AUTHENTICATED FAMILY caller. So the anonymous payload still carries no money field, which is what keeps
// `payment_links.single_reader` true in fact and not merely green.
// The field is ABSENT (not zero) when a programme has no fee items, or when its items span currencies. Absent
// => NO line. A programme with no fee is not a free programme; rendering HK$0.00 would assert a price that was
// never published. `fee_total_minor != null` is therefore the only condition — never `> 0`.
//
// NO DRILL for an un-enrolled card: with progdet deferred there is no family-facing programme-detail route
// (main.tsx has none), so the block's "View ›" would point nowhere. It is omitted rather than rendered dead.
// An ENROLLED card keeps its real drill — "Open ›" → /enrolments/{id}, the scoped space.
//
// "Coming soon" — B9 consumes S-READ-3 item 3. B7 omitted this chip rather than approximate it: the only
// proxy available then (current ∧ closed) also catches ENDED programmes and would have labelled them
// forthcoming. `enrolment_opens_at` is now served FROM THE COLUMN — the same source the read derives `status`
// from — so the chip and the status can never contradict each other. The predicate is the field itself:
// opens_at in the FUTURE. A programme with a null opens_at has no announced opening and is never "soon".
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
import { formatHktDayMonth, tsSort } from '../display/date';
import { formatMoney } from '../display/money';
import { StatusTag } from '../display/status';
import { SubPanel, EmptyState, HeroBanner } from '@/ds2';

const { Title, Text, Paragraph } = Typography;

interface Tri { en: string; tc: string; sc: string }
interface ProgrammeCard {
  id: number; code: string; name_en: string; name_tc: string; name_sc: string;
  phase: string; status: 'open' | 'closed'; brand_color: string; banner_url: string | null;
  tagline: Tri; category: Tri; age_range: Tri; duration: Tri;
  starts_on: string | null; enrolment_closes_on: string | null;
  // S-READ-3 item 3 — public, unconditional, straight off the column.
  enrolment_opens_at: string | null;
  // S-READ-3 item 2 — AUTHENTICATED FAMILY ONLY, and absent (never 0) for a programme with no fee items or
  // with items spanning currencies. Optional in the type because an anonymous browse legitimately lacks it.
  fee_total_minor?: number | null; currency?: string | null;
}
interface EnrolRow { id: string; student_id: number; programme_id: number; status: string; student_name: string | null }
// GET /my/children (S-READ-3 item 1). `name` is null for every non-active link, by ruling F-1.
interface ChildLink { student_id: number; name: string | null; status: string }
type Filter = 'all' | 'open' | 'soon' | 'enrolled';

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
  // B9 item 1 — the picker's roll comes from the LINK read, not from enrolment rows. Guardian-only: the
  // route is role:guardian and a student calling it 403s, so the URL is null for everyone else and
  // useResource stays idle rather than firing a request it knows will be refused.
  const links = useResource<{ data: ChildLink[] }>(isGuardian ? '/api/my/children' : null);
  const [filter, setFilter] = useState<Filter>('all');
  const [enrolFor, setEnrolFor] = useState<ProgrammeCard | null>(null);
  const [pick, setPick] = useState<number | undefined>(undefined);

  const rows = enrolments.data?.data ?? [];
  // (RIDER 2 removed the `enrolled` programme-id Set: its only consumer was the picker's disabled-option
  // condition, and the question is now per CHILD — see enrollableIn() — not per programme.)
  /**
   * B9 item 1 — the enrol picker's roll. It used to be derived from the guardian's ENROLMENT rows, which made
   * the register→link→enrol path a dead end: a newly-linked child with no enrolment yet was invisible in the
   * one picker that could give them their first one. GET /my/children is the link read, so a child appears
   * because they are LINKED, not because they are already enrolled.
   *
   * ACTIVE LINKS ONLY. A pending link is a relationship the academy has not approved (OD-23/OD-27), and
   * enrolling is an act of guardianship — so this guardian cannot yet perform it for that child. It is the
   * same logic F-1 uses to withhold the NAME: the read returns `name: null` for a pending row, so a pending
   * child could only ever render as "—" in a picker that would then 403 on submit. Filtered, not disabled:
   * a disabled row would advertise the existence of a link the guardian has not been granted.
   */
  const children = (links.data?.data ?? [])
    .filter((l) => l.status === 'active')
    .map((l) => ({ id: l.student_id, name: l.name }));

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

  /**
   * RIDER 2 — which ACTIVE-LINKED children can still be enrolled in THIS programme.
   *
   * The Enrol affordance used to key on `liveFor(programme).length === 0`, i.e. "this FAMILY holds no live
   * enrolment here". That is the wrong subject: one child enrolled hid the button for every sibling, so a
   * guardian with A7 and C1 unenrolled could not reach the picker on a programme another child was already
   * in. The predicate is now per CHILD — some active-linked child has no live enrolment here — which is the
   * question the button actually answers.
   *
   * Terminal enrolments do not count as live, so a withdrawn child becomes enrollable again, exactly as the
   * server would allow.
   */
  const enrollableIn = (id: number) =>
    children.filter((ch) => !rows.some((e) => e.student_id === ch.id && e.programme_id === id && !TERMINAL.has(e.status)));

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

  /**
   * The card's affordances, right-aligned opposite the chip.
   *
   * RIDER 2 — the MIXED case (some children enrolled, some not) shows BOTH facts, because they are facts
   * about DIFFERENT CHILDREN and neither may hide the other: the chip states that this family is enrolled
   * here (chip() is unchanged), the drill opens the one enrolment that exists, and the button enrols the
   * children who are not in it yet.
   *
   * GOLD, per-card ≤1. "Open ›" is the block's `.link-name`, GOLD text (L169), and it keeps that when it is
   * the card's only affordance. When the Enrol button renders beside it the drill goes QUIET: the action
   * outranks the drill, and a gold drill next to a quiet action inverts the hierarchy the rider states.
   * The Enrol button itself stays quiet (B7's standing ruling — the surface's one gold is the hero, and a
   * per-card gold would multiply across every open programme). So a mixed card carries ZERO action-golds,
   * an enrolled-only card exactly one, and the surface's single gold remains the hero button.
   */
  const action = (c: ProgrammeCard) => {
    const live = liveFor(c.id);
    const enrollable = c.status === 'open' && isGuardian ? enrollableIn(c.id) : [];
    // The drill must address ONE enrolment, so it renders only when exactly one exists — never a guess.
    const drill = live.length === 1
      ? (
        <Link
          to={`/enrolments/${live[0].id}`}
          // QUIET is MUTED, not antd's default link blue — dropping the gold style entirely would hand the
          // drill the exact blue B7 checked for and avoided. Gold when it is the card's only affordance.
          style={{ color: enrollable.length > 0 ? 'var(--ka-muted-fg)' : 'var(--ka-gold)' }}
        >
          {t('marketplace.open')}
        </Link>
      )
      : null;
    const enrol = enrollable.length > 0
      ? <Button size="small" onClick={() => { setEnrolFor(c); setPick(undefined); }}>{t('marketplace.enrol')}</Button>
      : null;

    if (drill && enrol) {
      return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 10 }}>{drill}{enrol}</span>;
    }
    if (drill) return drill;
    if (enrol) return enrol;
    // A guardian with nothing enrollable here (every active-linked child already live in it, or no active
    // link at all) gets no button — an affordance that opens an empty picker is a dead one.
    if (live.length > 0 || c.status === 'closed' || isGuardian) return null;
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

  // B9 item 5 — "soon" is the FIELD, not a proxy: enrolment has an announced opening and it has not arrived.
  // A null opens_at is not "soon" (nothing was announced) and an already-open programme is not "soon" either.
  //
  // PARSE VIA tsSort, never `new Date(...)`. The API emits "2026-09-19 16:00:00+00" — a space separator and a
  // BARE two-digit offset, which V8 returns Invalid Date for. The shared parser normalises both (`+00` →
  // `+00:00`), which is why every other surface reads these timestamps correctly. A hand-rolled
  // `.replace(' ','T')` here silently produced NaN, NaN > now() is false, and the filter rendered EMPTY —
  // caught by shooting the filtered state, not by reading the code.
  // tsSort maps null AND unparseable to +Infinity (nulls-last, for sorting), so the null guard must come
  // first: without it a programme with no announced opening would sort as infinitely far in the future and
  // read as "coming soon" forever.
  const comingSoon = (c: ProgrammeCard): boolean =>
    c.enrolment_opens_at != null && tsSort(c.enrolment_opens_at) > Date.now();

  const visible = (catalogue.data?.data ?? []).filter((c) =>
    filter === 'open' ? c.status === 'open' && liveFor(c.id).length === 0
      : filter === 'soon' ? comingSoon(c)
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

      {/* Filter chips (L591) — all FOUR the block carries, "Coming soon" included since B9. The ACTIVE chip
          is gold: a SELECTION state, not an action gold, so it does not count against the surface's one. */}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', margin: '2px 0 16px' }} role="group" aria-label={t('marketplace.title')}>
        {(['all', 'open', 'soon', 'enrolled'] as Filter[]).map((f) => (
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
                {/* term · ages, with the block's BOLD price at the right of the same row (L599). The price is
                    absent — not zero — whenever the read omitted it: anonymous browse, no fee items, or items
                    spanning currencies. Absent => the row is just the meta line, as B7 shipped it. */}
                <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12 }}>
                  <Text type="secondary">{[term(c), triOf(c.age_range, locale)].filter(Boolean).join(' · ')}</Text>
                  {c.fee_total_minor != null && (
                    <Text strong style={{ flex: '0 0 auto' }}>{formatMoney(c.fee_total_minor, c.currency ?? 'HKD', locale)}</Text>
                  )}
                </div>
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
        {/* RIDER 2 — the picker offers exactly the children who can still be enrolled in THIS programme.
            It was already per-programme, but it DISABLED an already-enrolled child rather than removing them:
            a greyed row states "your child is in this programme" in the one place that cannot say why, and it
            is the enrol dialog's job to offer choices, not to report enrolment state. Filtered now, from the
            same enrollableIn() the button's condition uses — so the button can never open an empty picker. */}
        {enrolFor && (() => {
          const options = enrollableIn(enrolFor.id);
          return options.length === 0
            ? <EmptyState size="inline" message={t('marketplace.noChildren')} />
            : (
              <>
                <Paragraph type="secondary">{t('marketplace.enrolBody')}</Paragraph>
                <Select
                  style={{ width: '100%' }}
                  placeholder={t('marketplace.pickChild')}
                  value={pick}
                  onChange={setPick}
                  options={options.map((ch) => ({ value: ch.id, label: personName(ch.name) }))}
                />
              </>
            );
        })()}
      </Modal>
    </div>
  );
}

// S-UX3-9 — guardian/teacher self-service (My Children · My Payments · My Students). All READS over
// built/existing-RLS endpoints; the one write surfaced is the existing mint-payment-link (guardian's own
// audited act — "get the payment link", NEVER "pay"; actual payment leaves via the /pay page). Refusals
// shown-not-hidden. The teacher roster is the STEP-1 gated read /api/my/students (allowlist {id,name}).
import { useState } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import { App, Button, Card, Descriptions, List, Space, Typography } from 'antd';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { personName, schoolName } from '../display/names';
import { formatMoney } from '../display/money';
import { formatHkt } from '../display/date';
import { StatusTag } from '../display/status';
// DS2 (restyle rollout — anchor STEP 1 MyChildren; C3 MyPayments/MyStudents). ALLOWED adopter already
// (import-guard, no change). C3 is container-framing only (List/Card→SubPanel); MyChildren is untouched.
import { SubPanel, ZoneStack, StatChip, EmptyState, UrgencyChip, urgencyLevel, urgencyDays, urgencyLabel, URGENCY } from '@/ds2';

const { Title, Paragraph, Text } = Typography;

interface Enrolment { id: string; programme_id: number; student_id: number; status: string; student_name: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string;
  // C6 — the WIDENED /api/enrolments columns (S-READ-2). Consent status/expiry come from the enrolment read now,
  // NOT a second /api/consent-requests fetch. NB there is deliberately NO amount field: the fee amount stays on
  // the guardian-gated /api/orders read (P-3/B-18 — never on a read a student also receives).
  team_name: string | null; consent_status: string | null; consent_expires_at: string | null;
  school_name_en: string | null; school_name_tc: string | null; school_name_sc: string | null;
  next_session_title: string | null; next_session_starts_at: string | null }
interface Order { id: string; programme_id: number; student_id: number; payer_party: string; status: string; total_amount_minor: number; currency: string; payment_due_at: string | null; student_name: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string }
interface Receipt { id: string; order_id: string; receipt_number: number; amount_minor: number; currency: string; issued_at: string }
interface StudentRow { student_id: number; student_name: string | null }
interface OrderLine { id: string; name_en: string; name_tc: string; name_sc: string; amount_minor: number; currency: string }

function progName(r: { programme_name_en: string; programme_name_tc: string; programme_name_sc: string }, locale: KaLocale): string {
  return (locale === 'zh-TC' ? r.programme_name_tc : locale === 'zh-SC' ? r.programme_name_sc : r.programme_name_en) || r.programme_name_en;
}

function lineName(l: OrderLine, locale: KaLocale): string {
  return (locale === 'zh-TC' ? l.name_tc : locale === 'zh-SC' ? l.name_sc : l.name_en) || l.name_en;
}

// R1-G — an order's line items (the additive read /api/orders/{id}/lines; order_lines is INSERT-only, BI-5).
// DISPLAY ONLY: the snapshotted trilingual line name (tri pattern) + amount (formatMoney). Lazy — the read
// only fires when the row is expanded. Closes the Part-1 headline (order_line_items were rendered nowhere).
function OrderLines({ orderId }: { orderId: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const res = useResource<{ data: OrderLine[] }>(`/api/orders/${orderId}/lines`);
  return (
    <DataBoundary loading={res.loading} error={res.error} empty={(res.data?.data.length ?? 0) === 0}>
      <Descriptions size="small" column={1} bordered style={{ marginTop: 8 }}>
        {(res.data?.data ?? []).map((l) => (
          <Descriptions.Item key={l.id} label={lineName(l, locale)}>
            {formatMoney(l.amount_minor, l.currency, locale)}
          </Descriptions.Item>
        ))}
      </Descriptions>
      <div style={{ marginTop: 6 }}><Text type="secondary">{t('order.lines.snapshotNote')}</Text></div>
    </DataBoundary>
  );
}

// R1-G — one payable order row with an expandable line-items detail. The status tag + getLink action + the
// urgency row treatment are BYTE-IDENTICAL to the pre-R1-G renderItem; only the expandable detail is added.
function PayableOrderItem({ o, locale, onGetLink }: { o: Order; locale: KaLocale; onGetLink: (o: Order) => void }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const lvl = o.status === 'issued' ? urgencyLevel(o.payment_due_at, URGENCY.payment) : 'none';
  return (
    <List.Item className={lvl !== 'none' ? `ds2-urgent--${lvl}` : undefined} actions={[
      <StatusTag key="st" domain="orderStatus" value={o.status} />,
      o.status === 'issued'
        ? <Button key="lnk" size="small" type="primary" className="ka-cta" onClick={() => onGetLink(o)}>{t('selfService.getLink')}</Button>
        : <span key="lnk" />,
    ]}>
      <div style={{ width: '100%' }}>
        {/* AD-4: name the child on the payable row — "Demo Student A6 · Summer STEM 2026". */}
        <List.Item.Meta
          title={o.student_name ? `${personName(o.student_name)} · ${progName(o, locale)}` : progName(o, locale)}
          description={
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <Text type="secondary">{formatMoney(o.total_amount_minor, o.currency, locale)}{o.payment_due_at ? ` · ${t('selfService.due')} ${formatHkt(o.payment_due_at, locale)}` : ''}</Text>
              {lvl !== 'none' && <UrgencyChip level={lvl} label={urgencyLabel(lvl, urgencyDays(o.payment_due_at), t)} />}
            </span>
          }
        />
        <Button type="link" size="small" style={{ paddingLeft: 0 }} onClick={() => setOpen((v) => !v)} aria-expanded={open}>
          {open ? t('order.lines.hide') : t('order.lines.show')}
        </Button>
        {open && <OrderLines orderId={o.id} />}
      </div>
    </List.Item>
  );
}

function childInitials(name: string | null): string {
  const n = personName(name);
  const parts = n.split(/\s+/).filter(Boolean);
  return (parts.length >= 2 ? parts[0][0] + parts[1][0] : n.slice(0, 2)).toUpperCase();
}

// ── Guardian: one child's enrolment as a DRILL row (C2-LIST / C6). The row's object is the ENROLMENT, so the
// whole row navigates to the scoped space /enrolments/:enrolmentId (ruling #5); the action button stops
// propagation so it fires its own destination, not the drill. §3.1: informative values are plain text; only an
// ACTIONABLE state (sign / get-link) is a button. goldKey carries the child card's SINGLE gold (ruling #2) — the
// button is gold only when it is the child's one gold target.
//
// THE ROW SET IS EMERGENT, NOT A TABLE OF STATES (C6 ruling #2): each row renders IFF its computed value is
// non-empty. Consent comes from the enrolment read's consent_status/expires now (no second fetch); Fees still
// come from the guardian's own /api/orders (the amount is not — must not be — on the shared list read, P-3/B-18);
// the Status row always renders (a status is always present) and carries the team name once teamed; Next-session
// renders only when a future session exists. Do NOT refactor into per-state branches — the shape falls out of
// which values exist. ──
function ckey(studentId: number, programmeId: number): string { return `${studentId}:${programmeId}`; }

// The child card's one gold target: the first actionable row in enrolment order (consent before fees). null when
// the child has no action — that card then carries NO gold (ruling #2). Consent-actionable is now read straight
// off the enrolment's consent_status; fees-actionable off the guardian's order.
function goldTarget(enrolments: Enrolment[], orderBy: Map<string, Order>): string | null {
  for (const e of enrolments) {
    if (e.consent_status && ['sent', 'viewed'].includes(e.consent_status)) return `${e.id}:consent`;
    const o = orderBy.get(ckey(e.student_id, e.programme_id));
    if (o && o.status === 'issued') return `${e.id}:fees`;
  }
  return null;
}

function ChildEnrolmentRow({ e, order, goldKey }: { e: Enrolment; order?: Order; goldKey: string | null }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const navigate = useNavigate();
  const prog = progName(e, locale);
  const stop = (ev: MouseEvent) => ev.stopPropagation();
  const rows: { label: string; node: ReactNode }[] = [];

  // Consent — the guardian CAN sign, so an actionable consent is a gold-eligible button plus an expiry countdown
  // driven by the real consent_expires_at now on the read.
  if (e.consent_status && ['sent', 'viewed'].includes(e.consent_status)) {
    const gold = goldKey === `${e.id}:consent`;
    const lvl = urgencyLevel(e.consent_expires_at, URGENCY.consent);
    rows.push({ label: `${t('enrolCard.consent')} · ${prog}`, node: (
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
        {lvl !== 'none' && <UrgencyChip level={lvl} label={urgencyLabel(lvl, urgencyDays(e.consent_expires_at), t)} />}
        <Link to="/consents" onClick={stop}><Button type={gold ? 'primary' : 'default'} size="small" className={gold ? 'ka-cta' : undefined}>{t('selfService.reviewSign')}</Button></Link>
      </span>
    ) });
  } else if (e.consent_status) {
    rows.push({ label: `${t('enrolCard.consent')} · ${prog}`, node: (
      <Text type="secondary">{e.consent_status === 'signed' ? t('selfService.consentMet') : t(`consent.status.${e.consent_status}`)}</Text>
    ) });
  }
  if (order) {
    if (order.status === 'issued') {
      const gold = goldKey === `${e.id}:fees`;
      const due = order.payment_due_at ? ` · ${t('selfService.due')} ${formatHkt(order.payment_due_at, locale)}` : '';
      rows.push({ label: `${t('enrolCard.fees')} · ${prog}`, node: (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          <Text type="secondary">{formatMoney(order.total_amount_minor, order.currency, locale)}{due}</Text>
          <Link to="/my/payments" onClick={stop}><Button type={gold ? 'primary' : 'default'} size="small" className={gold ? 'ka-cta' : undefined}>{t('selfService.getLink')}</Button></Link>
        </span>
      ) });
    } else {
      rows.push({ label: `${t('enrolCard.fees')} · ${prog}`, node: <Text type="secondary">{formatMoney(order.total_amount_minor, order.currency, locale)}</Text> });
    }
  }
  // Status — always present, so this row always renders (the card is never invisible). Carries the team name once
  // teamed; before a team there is simply no "· team" suffix. member_count is D-7 PROTOTYPE-WRONG — never shown.
  rows.push({ label: prog, node: (
    <Text type="secondary">{t(`enrol.status.${e.status}`)}{e.team_name ? ` · ${e.team_name}` : ''}</Text>
  ) });
  // Next session — title + start, when a future session exists.
  if (e.next_session_title) {
    rows.push({ label: `${t('enrolCard.nextSession')} · ${prog}`, node: (
      <Text type="secondary">{`${e.next_session_title} · ${formatHkt(e.next_session_starts_at ?? '', locale)}`}</Text>
    ) });
  }

  return (
    <SubPanel tone="neutral">
      <div
        role="button"
        tabIndex={0}
        style={{ cursor: 'pointer' }}
        onClick={() => navigate(`/enrolments/${e.id}`)}
        onKeyDown={(ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); navigate(`/enrolments/${e.id}`); } }}
      >
        {rows.map((r, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, minHeight: 32 }}>
            <span className="ds2-atom">{r.label}</span>
            <span style={{ textAlign: 'right' }}>{r.node}</span>
          </div>
        ))}
      </div>
    </SubPanel>
  );
}

// ── Guardian: My Children (C2-LIST / C6 — each child card's rows are the Consent · Fees · Status · Next-session
// drill rows). Now TWO reads, matched client-side by (student, programme): /api/enrolments (WIDENED by S-READ-2 to
// carry consent_status/expires + school + team + next-session) grouped by child, and /api/orders (KEPT — the fee
// AMOUNT is guardian-only and must never ride the shared enrolment read, P-3/B-18). The separate
// /api/consent-requests fetch is GONE (3 reads → 2); consent state comes off the enrolment read. Product density. ──
export function MyChildren() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const res = useResource<{ data: Enrolment[] }>('/api/enrolments');
  const ord = useResource<{ data: Order[] }>('/api/orders');

  const orderBy = new Map((ord.data?.data ?? []).map((o) => [ckey(o.student_id, o.programme_id), o]));

  // group the guardian's children's enrolments by child (RLS already scopes to own children)
  const byChild = new Map<number, { name: string | null; enrolments: Enrolment[] }>();
  for (const e of res.data?.data ?? []) {
    const entry = byChild.get(e.student_id) ?? { name: e.student_name, enrolments: [] };
    entry.enrolments.push(e);
    byChild.set(e.student_id, entry);
  }
  // AL-8: order the children by name (stable, locale-aware) so the list reads predictably.
  const children = Array.from(byChild.entries()).sort((a, b) => personName(a[1].name).localeCompare(personName(b[1].name)));

  return (
    <div data-density="product">
      <Space direction="vertical" size="large" style={{ width: '100%' }}>
        <Title level={3} style={{ marginBottom: 0 }}>{t('selfService.childrenTitle')}</Title>
        <DataBoundary loading={res.loading} error={res.error} empty={children.length === 0}>
          <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            {children.map(([studentId, { name, enrolments }]) => {
              const goldKey = goldTarget(enrolments, orderBy); // ruling #2: ≤1 gold per child card
              // A child sits on one active school link (or none — a direct-to-academy student), so the school is a
              // per-child fact: take it off any enrolment. null → omit the line (never render "—").
              const school = schoolName(enrolments.find((e) => schoolName(e, locale)) ?? enrolments[0], locale);
              return (
              <Card key={studentId}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 14 }}>
                  <div style={{ width: 44, height: 44, borderRadius: '50%', background: 'linear-gradient(135deg,#2c2338,#3a2f4a)', display: 'grid', placeItems: 'center', fontFamily: 'var(--ka-font-display)', fontWeight: 700, color: 'var(--ka-gold)', border: '1px solid var(--ka-border)', flex: '0 0 auto' }}>
                    {childInitials(name)}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <Title level={5} style={{ margin: 0 }}>{personName(name)}</Title>
                    {/* Subtitle: the child's SCHOOL (now served — school_name_{en,tc,sc}, S-READ-2), then an
                        enrolment count pluralised via CLDR (_one/_other). School omitted for a direct student. */}
                    <div style={{ marginTop: 6, display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                      {school && <Text type="secondary">{school}</Text>}
                      <StatChip value={enrolments.length} label={t('selfService.enrolments', { count: enrolments.length })} />
                    </div>
                  </div>
                  {/* DELIBERATE, DOCUMENTED DIVERGENCE (a11y) from the prototype's whole-card drill → the child
                      record. Any one ground is sufficient: (1) the rows are role=button drills to /enrolments/:id
                      (D-1), so a card-level drill nests interactives — invalid HTML, ambiguous in the a11y tree;
                      (2) stopPropagation is mouse-only — nothing for keyboard/screen readers; (3) card→child-record
                      conflicts with rows→enrolment-space. So the drill is an EXPLICIT link. "View record" → the
                      child record. "View sessions" → /family/sessions — the guardian's ONLY entry since C1-SHELL
                      demoted the nav slot; FLAG: redundant once the scoped-space Sessions tab covers per-child
                      sessions — remove it then, not now. */}
                  <Space size="middle">
                    <Link to={`/my/children/${studentId}`}>{t('selfService.viewProfile')}</Link>
                    <Link to={`/family/sessions?student=${studentId}`}>{t('selfService.viewSessions')}</Link>
                  </Space>
                </div>
                <ZoneStack>
                  {enrolments.map((e) => (
                    <ChildEnrolmentRow
                      key={e.id}
                      e={e}
                      order={orderBy.get(ckey(e.student_id, e.programme_id))}
                      goldKey={goldKey}
                    />
                  ))}
                </ZoneStack>
              </Card>
              );
            })}
          </Space>
        </DataBoundary>
        {/* Prototype's trailing "Enrol a child" card (item 3) — the guardian's marketplace entry (C1-SHELL
            demoted the nav slot, so this IS the prototype's path). Always available, incl. an empty roll. Its
            gold CTA is its OWN card's one gold — not a second gold on a child card. */}
        <Card style={{ background: 'var(--ka-muted)', boxShadow: 'none' }}>
          <Title level={5} style={{ marginTop: 0 }}>{t('selfService.enrolChild')}</Title>
          <Link to="/marketplace"><Button type="primary" className="ka-cta">{t('selfService.enrolChildCta')}</Button></Link>
        </Card>
      </Space>
    </div>
  );
}

// ── Guardian: My Payments (read-only obligations/receipts + mint-link "get the payment link") ───────
export function MyPayments() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { message, modal } = App.useApp();
  const orders = useResource<{ data: Order[] }>('/api/orders');
  const receipts = useResource<{ data: Receipt[] }>('/api/receipts');

  // Payments view shows the FAMILY-PAYABLE orders. School-payer orders are RLS-visible (familyRead keys on
  // student_id) but are the SCHOOL's to settle — excluded from the family's pay view by this display filter.
  const payable = (orders.data?.data ?? []).filter((o) => o.payer_party === 'guardian' || o.payer_party === 'student');

  const getLink = async (order: Order) => {
    const r = await mutate(`/api/my/orders/${order.id}/payment-link`);
    if (r.ok) {
      const url = (r.data as { url?: string } | undefined)?.url;
      // "get the payment link" — never "paid". The guardian forwards this to whoever pays, via /pay.
      modal.info({ title: t('selfService.linkReady'), content: <Text copyable>{url}</Text> });
    } else {
      // shown-not-hidden: the server's refusal (e.g. 422 "Order is {status} — nothing to pay")
      void message.error(r.message ?? t('mutate.failed'));
    }
  };

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('selfService.paymentsTitle')}</Title>
        <Paragraph type="secondary">{t('selfService.paymentsSubtitle')}</Paragraph>
      </div>
      {/* AL-4: section boundary → inline empty (not a route-body page empty). */}
      <DataBoundary loading={orders.loading} error={orders.error} empty={payable.length === 0} emptySize="inline">
        <SubPanel tone="neutral">
          <List<Order>
            dataSource={payable}
            renderItem={(o) => <PayableOrderItem key={o.id} o={o} locale={locale} onGetLink={(ord) => void getLink(ord)} />}
          />
        </SubPanel>
      </DataBoundary>

      {/* AL-3: "Receipts" is a section title (level 5) INSIDE its SubPanel (C6 idiom) — the header stays
          even when empty, so the DataBoundary lives inside. AL-4: inline empty. AL-9: list size="small". */}
      <SubPanel tone="neutral">
        <Title level={5} style={{ margin: '0 0 8px' }}>{t('selfService.receiptsTitle')}</Title>
        <DataBoundary loading={receipts.loading} error={receipts.error} empty={(receipts.data?.data.length ?? 0) === 0} emptySize="inline">
          <List<Receipt>
            size="small"
            dataSource={receipts.data?.data ?? []}
            renderItem={(r) => (
              <List.Item key={r.id} actions={[<Text key="a" strong>{formatMoney(r.amount_minor, r.currency, locale)}</Text>]}>
                <List.Item.Meta
                  title={`${t('selfService.receipt')} #${r.receipt_number}`}
                  description={<Text type="secondary">{formatHkt(r.issued_at, locale)}</Text>}
                />
              </List.Item>
            )}
          />
        </DataBoundary>
      </SubPanel>
    </Space>
  );
}

// ── Teacher: My Students (the STEP-1 gated read — school roster, allowlist {id, name}) ───────────────
export function MyStudents() {
  const { t } = useTranslation();
  const res = useResource<{ data: StudentRow[] }>('/api/teacher/students');

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('selfService.studentsTitle')}</Title>
        <Paragraph type="secondary">{t('selfService.studentsSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={res.loading} error={res.error} empty={(res.data?.data.length ?? 0) === 0}>
        <List<StudentRow>
          size="small"
          grid={{ gutter: 12, xs: 1, sm: 2, md: 3 }}
          dataSource={res.data?.data ?? []}
          renderItem={(s) => (
            <List.Item key={s.student_id}>
              <SubPanel tone="neutral"><Text strong>{personName(s.student_name)}</Text></SubPanel>
            </List.Item>
          )}
          locale={{ emptyText: <EmptyState size="inline" message={t('selfService.noStudents')} /> }}
        />
      </DataBoundary>
    </Space>
  );
}

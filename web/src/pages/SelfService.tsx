// S-UX3-9 — guardian/teacher self-service (My Children · My Payments · My Students). All READS over
// built/existing-RLS endpoints; the one write surfaced is the existing mint-payment-link (guardian's own
// audited act — "get the payment link", NEVER "pay"; actual payment leaves via the /pay page). Refusals
// shown-not-hidden. The teacher roster is the STEP-1 gated read /api/my/students (allowlist {id,name}).
import { useState } from 'react';
import { App, Button, Card, Descriptions, List, Space, Typography } from 'antd';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { personName } from '../display/names';
import { formatMoney } from '../display/money';
import { formatHkt } from '../display/date';
import { StatusTag } from '../display/status';
// DS2 (restyle rollout — anchor STEP 1 MyChildren; C3 MyPayments/MyStudents). ALLOWED adopter already
// (import-guard, no change). C3 is container-framing only (List/Card→SubPanel); MyChildren is untouched.
import { SubPanel, ZoneStack, Attest, StatChip, StateBadge, Seal, EmptyState, UrgencyChip, urgencyLevel, urgencyDays, urgencyLabel, URGENCY } from '@/ds2';

const { Title, Paragraph, Text } = Typography;

interface Enrolment { id: string; programme_id: number; student_id: number; status: string; student_name: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string }
interface Order { id: string; programme_id: number; student_id: number; payer_party: string; status: string; total_amount_minor: number; currency: string; payment_due_at: string | null; student_name: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string }
interface Receipt { id: string; order_id: string; receipt_number: number; amount_minor: number; currency: string; issued_at: string }
interface StudentRow { student_id: number; student_name: string | null }
interface ConsentStatus { consent_met: boolean; your_signature_needed: boolean }
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

// ── Guardian: one enrolment's zone. Consent is per-PROGRAMME in the live data (derivedStatus per
// student+programme — the authoritative read, UNCHANGED). The consent advisory renders via Attest
// (attested → onViewRecord the record; action → Review & sign); pending/loading → a neutral zone. ──
function EnrolmentZone({ e }: { e: Enrolment }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const navigate = useNavigate();
  const res = useResource<ConsentStatus>(`/api/my/students/${e.student_id}/consent-status?programme_id=${e.programme_id}`);
  const c = res.data;
  const prog = progName(e, locale);
  const enrolTag = <StatusTag domain="enrolmentStatus" value={e.status} />;

  if (c?.your_signature_needed) {
    return (
      <Attest
        tone="action"
        icon={<StateBadge state="action" title={t('selfService.consentNeeded')} />}
        status={prog}
        action={
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
            {enrolTag}
            <Link to="/consents"><Button type="primary" size="small" className="ka-cta">{t('selfService.reviewSign')}</Button></Link>
          </span>
        }
      />
    );
  }
  if (c?.consent_met) {
    return (
      <Attest
        tone="attested"
        icon={<Seal size={20} />}
        status={prog}
        onViewRecord={() => navigate('/consents')}
        viewRecordLabel={t('selfService.viewRecord')}
        action={enrolTag}
      />
    );
  }
  return (
    <SubPanel tone="neutral">
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <span className="ds2-atom">{prog}</span>
        <span style={{ flex: 1 }} />
        {enrolTag}
      </div>
    </SubPanel>
  );
}

// ── Guardian: My Children (DS2 restyle — markup only; reads UNCHANGED: /api/enrolments grouped by child
// + per-enrolment consent-status). Product density. ──
export function MyChildren() {
  const { t } = useTranslation();
  const res = useResource<{ data: Enrolment[] }>('/api/enrolments');

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
            {children.map(([studentId, { name, enrolments }]) => (
              <Card key={studentId}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 14 }}>
                  <div style={{ width: 44, height: 44, borderRadius: '50%', background: 'linear-gradient(135deg,#2c2338,#3a2f4a)', display: 'grid', placeItems: 'center', fontFamily: 'var(--ka-font-display)', fontWeight: 700, color: 'var(--ka-gold)', border: '1px solid var(--ka-border)', flex: '0 0 auto' }}>
                    {childInitials(name)}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <Title level={5} style={{ margin: 0 }}>{personName(name)}</Title>
                    {/* AD-3: pluralize the label via CLDR (_one/_other) — "1 programme" / "2 programmes". */}
                    <div style={{ marginTop: 6 }}><StatChip value={enrolments.length} label={t('selfService.programmes', { count: enrolments.length })} /></div>
                  </div>
                  {/* R1-G: preselect this child in the sessions picker (falls back to first child if absent). */}
                  <Link to={`/family/sessions?student=${studentId}`}>{t('selfService.viewSessions')}</Link>
                </div>
                <ZoneStack>
                  {enrolments.map((e) => <EnrolmentZone key={e.id} e={e} />)}
                </ZoneStack>
              </Card>
            ))}
          </Space>
        </DataBoundary>
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

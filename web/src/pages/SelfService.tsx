// S-UX3-9 — guardian/teacher self-service (My Children · My Payments · My Students). All READS over
// built/existing-RLS endpoints; the one write surfaced is the existing mint-payment-link (guardian's own
// audited act — "get the payment link", NEVER "pay"; actual payment leaves via the /pay page). Refusals
// shown-not-hidden. The teacher roster is the STEP-1 gated read /api/my/students (allowlist {id,name}).
import { App, Button, Card, Empty, List, Space, Tag, Typography } from 'antd';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { personName } from '../display/names';
import { formatMoney } from '../display/money';
import { formatHkt } from '../display/date';
import { StatusTag } from '../display/status';

const { Title, Paragraph, Text } = Typography;

interface Enrolment { id: string; programme_id: number; student_id: number; status: string; student_name: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string }
interface Order { id: string; programme_id: number; student_id: number; payer_party: string; status: string; total_amount_minor: number; currency: string; payment_due_at: string | null; programme_name_en: string; programme_name_tc: string; programme_name_sc: string }
interface Receipt { id: string; order_id: string; receipt_number: number; amount_minor: number; currency: string; issued_at: string }
interface StudentRow { student_id: number; student_name: string | null }
interface ConsentStatus { consent_met: boolean; your_signature_needed: boolean }

function progName(r: { programme_name_en: string; programme_name_tc: string; programme_name_sc: string }, locale: KaLocale): string {
  return (locale === 'zh-TC' ? r.programme_name_tc : locale === 'zh-SC' ? r.programme_name_sc : r.programme_name_en) || r.programme_name_en;
}

// ── Guardian: per-child consent chip (REUSES the existing derivedStatus elevation — not a new one) ──
function ConsentChip({ studentId, programmeId }: { studentId: number; programmeId: number }) {
  const { t } = useTranslation();
  const res = useResource<ConsentStatus>(`/api/my/students/${studentId}/consent-status?programme_id=${programmeId}`);
  if (res.loading || res.error || !res.data) return null;
  if (res.data.your_signature_needed) return <Tag color="warning">{t('selfService.consentNeeded')}</Tag>;
  return <Tag color={res.data.consent_met ? 'success' : 'default'}>{t(res.data.consent_met ? 'selfService.consentMet' : 'selfService.consentPending')}</Tag>;
}

// ── Guardian: My Children (enrolments + consent + sessions link, per child) ─────────────────────────
export function MyChildren() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const res = useResource<{ data: Enrolment[] }>('/api/enrolments');

  // group the guardian's children's enrolments by child (RLS already scopes to own children)
  const byChild = new Map<number, { name: string | null; enrolments: Enrolment[] }>();
  for (const e of res.data?.data ?? []) {
    const entry = byChild.get(e.student_id) ?? { name: e.student_name, enrolments: [] };
    entry.enrolments.push(e);
    byChild.set(e.student_id, entry);
  }
  const children = Array.from(byChild.entries());

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('selfService.childrenTitle')}</Title>
        <Paragraph type="secondary">{t('selfService.childrenSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={res.loading} error={res.error} empty={children.length === 0}>
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
          {children.map(([studentId, { name, enrolments }]) => (
            <Card key={studentId} size="small" title={personName(name)}
              extra={<Link to="/family/sessions">{t('selfService.viewSessions')}</Link>}>
              <List<Enrolment>
                dataSource={enrolments}
                renderItem={(e) => (
                  <List.Item key={e.id} actions={[
                    <StatusTag key="st" domain="enrolmentStatus" value={e.status} />,
                    <ConsentChip key="cc" studentId={e.student_id} programmeId={e.programme_id} />,
                  ]}>
                    <List.Item.Meta title={progName(e, locale)} />
                  </List.Item>
                )}
              />
            </Card>
          ))}
        </Space>
      </DataBoundary>
    </Space>
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
      <DataBoundary loading={orders.loading} error={orders.error} empty={payable.length === 0}>
        <List<Order>
          dataSource={payable}
          renderItem={(o) => (
            <List.Item key={o.id} actions={[
              <StatusTag key="st" domain="orderStatus" value={o.status} />,
              o.status === 'issued'
                ? <Button key="lnk" size="small" type="primary" className="ka-cta" onClick={() => void getLink(o)}>{t('selfService.getLink')}</Button>
                : <span key="lnk" />,
            ]}>
              <List.Item.Meta
                title={progName(o, locale)}
                description={<Text type="secondary">{formatMoney(o.total_amount_minor, o.currency, locale)}{o.payment_due_at ? ` · ${t('selfService.due')} ${formatHkt(o.payment_due_at, locale)}` : ''}</Text>}
              />
            </List.Item>
          )}
        />
      </DataBoundary>

      <div>
        <Title level={4} style={{ marginBottom: 0 }}>{t('selfService.receiptsTitle')}</Title>
      </div>
      <DataBoundary loading={receipts.loading} error={receipts.error} empty={(receipts.data?.data.length ?? 0) === 0}>
        <List<Receipt>
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
          grid={{ gutter: 12, xs: 1, sm: 2, md: 3 }}
          dataSource={res.data?.data ?? []}
          renderItem={(s) => (
            <List.Item key={s.student_id}>
              <Card size="small"><Text strong>{personName(s.student_name)}</Text></Card>
            </List.Item>
          )}
          locale={{ emptyText: <Empty description={t('selfService.noStudents')} /> }}
        />
      </DataBoundary>
    </Space>
  );
}

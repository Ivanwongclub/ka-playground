// S04C STEP 1 (D-ii) — the public payment PAGE that a forwardable OD-44 link
// opens. It renders the initials-only order (never the child's name, never JSON)
// and lets an anonymous third party (a grandparent) pay. Every non-payable state
// — unknown, expired, paid, paying — collapses to one "not available" shape, the
// same constant-shape discipline the API enforces.
import { useEffect, useState } from 'react';
import { Button, Card, Descriptions, Flex, Result, Spin, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { asset } from '../assets';
import { LocaleSwitcher } from '../components/LocaleSwitcher';

const { Title, Paragraph } = Typography;

type Payload = {
  order_reference: string;
  amount_minor: number;
  currency: string;
  programme_name: { en: string; tc: string; sc: string };
  student_initials: string;
};

export function PublicPay() {
  const { t, i18n } = useTranslation();
  const { token = '' } = useParams();
  const [state, setState] = useState<'loading' | 'ready' | 'gone' | 'paid'>('loading');
  const [payload, setPayload] = useState<Payload | null>(null);
  const [receipt, setReceipt] = useState<number | null>(null);
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    fetch(`/api/pay/${token}`, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('gone'))))
      .then((b: Payload) => {
        setPayload(b);
        setState('ready');
      })
      .catch(() => setState('gone'));
  }, [token]);

  const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(i18n.language === 'en' ? 'en-HK' : 'zh-HK', { style: 'currency', currency }).format(minor / 100);

  const programmeName = (p: Payload['programme_name']) =>
    i18n.language === 'zh-TC' ? p.tc : i18n.language === 'zh-SC' ? p.sc : p.en;

  const pay = async () => {
    setPaying(true);
    try {
      const res = await fetch(`/api/pay/${token}/confirm`, { method: 'POST', headers: { Accept: 'application/json' } });
      if (res.ok) {
        const b = (await res.json()) as { paid: boolean; receipt_number: number | null };
        setReceipt(b.receipt_number);
        setState('paid');
        return;
      }
      setState('gone'); // race loser / expired — one shape
    } catch {
      setState('gone');
    } finally {
      setPaying(false);
    }
  };

  return (
    <div className="ka-pay">
      <div style={{ position: 'fixed', top: 16, right: 16, zIndex: 10 }}><LocaleSwitcher /></div>
      <Card className="ka-pay-card">
        <Flex vertical align="center" gap={4} style={{ marginBottom: 16 }}>
          <img src={asset('brand/armour-academy-logo.webp')} alt={t('pay.logoAlt')} style={{ height: 36 }} />
          <Title level={3} style={{ margin: 0 }}>{t('pay.title')}</Title>
        </Flex>

        {state === 'loading' && <Flex justify="center" style={{ padding: 32 }}><Spin /></Flex>}

        {state === 'gone' && (
          <Result status="warning" title={t('pay.goneTitle')} subTitle={t('pay.goneSubtitle')} />
        )}

        {state === 'paid' && (
          <Result
            status="success"
            title={t('pay.paidTitle')}
            subTitle={receipt ? t('pay.paidReceipt', { number: receipt }) : t('pay.paidSubtitle')}
          />
        )}

        {state === 'ready' && payload && (
          <>
            <Paragraph type="secondary">{t('pay.intro')}</Paragraph>
            <Descriptions column={1} bordered size="small" style={{ marginBottom: 16 }}>
              <Descriptions.Item label={t('pay.programme')}>{programmeName(payload.programme_name)}</Descriptions.Item>
              <Descriptions.Item label={t('pay.student')}>{payload.student_initials}</Descriptions.Item>
              <Descriptions.Item label={t('pay.reference')}>{payload.order_reference}</Descriptions.Item>
              <Descriptions.Item label={t('pay.amount')}>{money(payload.amount_minor, payload.currency)}</Descriptions.Item>
            </Descriptions>
            <Button type="primary" block loading={paying} onClick={pay}>
              {t('pay.payNow', { amount: money(payload.amount_minor, payload.currency) })}
            </Button>
            <Paragraph type="secondary" style={{ marginTop: 12, textAlign: 'center', fontSize: 13 }}>{t('pay.privacyNote')}</Paragraph>
          </>
        )}
      </Card>
    </div>
  );
}

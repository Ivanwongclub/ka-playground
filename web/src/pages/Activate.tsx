// S04C STEP 2 (OD-29 model B) — the account activation page the approval email
// links to. One act: verify the address (proven by holding the single-use token)
// AND set the first password. On success the account can sign in. Trilingual.
import { useState } from 'react';
import { Alert, Button, Card, Flex, Form, Input, Result, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { asset } from '../assets';
import { LocaleSwitcher } from '../components/LocaleSwitcher';

const { Title, Paragraph } = Typography;

export function Activate() {
  const { t } = useTranslation();
  const { token = '' } = useParams();
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (values: { password: string; password_confirmation: string }) => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch('/api/register/activate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ token, ...values }),
      });
      if (res.ok) {
        setDone(true);
        return;
      }
      setError(res.status === 422 ? t('activate.errorToken') : t('activate.errorGeneric'));
    } catch {
      setError(t('activate.errorNetwork'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="ka-register">
      <div style={{ position: 'fixed', top: 16, right: 16, zIndex: 10 }}><LocaleSwitcher /></div>
      <Card className="ka-pay-card">
        <Flex vertical align="center" gap={4} style={{ marginBottom: 16 }}>
          <img src={asset('brand/armour-academy-logo.webp')} alt={t('activate.logoAlt')} style={{ height: 36 }} />
          <Title level={3} style={{ margin: 0 }}>{t('activate.title')}</Title>
        </Flex>

        {done ? (
          <Result
            status="success"
            title={t('activate.doneTitle')}
            subTitle={t('activate.doneSubtitle')}
            extra={<Link to="/login"><Button type="primary">{t('activate.toLogin')}</Button></Link>}
          />
        ) : (
          <>
            <Paragraph type="secondary">{t('activate.subtitle')}</Paragraph>
            {error && <Alert type="error" showIcon message={error} style={{ marginBottom: 16 }} />}
            <Form layout="vertical" onFinish={submit} requiredMark={false} disabled={busy}>
              <Form.Item name="password" label={t('activate.password')} rules={[{ required: true, min: 8 }]}>
                <Input.Password autoComplete="new-password" />
              </Form.Item>
              <Form.Item
                name="password_confirmation"
                label={t('activate.confirm')}
                dependencies={['password']}
                rules={[
                  { required: true },
                  ({ getFieldValue }) => ({
                    validator: (_, v) =>
                      !v || getFieldValue('password') === v ? Promise.resolve() : Promise.reject(new Error(t('activate.mismatch'))),
                  }),
                ]}
              >
                <Input.Password autoComplete="new-password" />
              </Form.Item>
              <Button type="primary" htmlType="submit" block loading={busy}>{t('activate.submit')}</Button>
            </Form>
          </>
        )}
      </Card>
    </div>
  );
}

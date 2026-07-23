// S01 login — split-screen per ASSET-MANIFEST §4: AA logo (user-facing mark),
// full-bleed auth-assets photo with §11.2 duotone, carried parent quote.
// Invitation-only: no sign-up affordance of any kind (L4/SR001).
import { useState } from 'react';
import { Alert, Button, Checkbox, Flex, Input, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { asset } from '../assets';
import { setToken } from '../auth/session';

const { Title, Paragraph, Text } = Typography;

export function Login() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password, remember }),
      });
      if (res.ok) {
        const body = (await res.json()) as { token: string };
        setToken(body.token);
        void navigate('/');
        return;
      }
      if (res.status === 403) setError(t('login.errorUnverified'));
      else if (res.status === 423) setError(t('login.errorLocked'));
      else setError(t('login.errorCredentials'));
    } catch {
      setError(t('login.errorNetwork'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="ka-login">
      <section className="ka-login-form">
        <img
          src={asset('brand/armour-academy-logo.webp')}
          alt={t('login.logoAlt')}
          className="ka-login-logo"
        />
        <Title level={1}>{t('login.title')}</Title>
        <Paragraph type="secondary">{t('login.subtitle')}</Paragraph>
        {error && <Alert type="error" showIcon message={error} style={{ marginBottom: 16 }} />}
        <Flex vertical gap={16}>
          <div>
            <label className="ka-label" htmlFor="login-email">{t('login.email')}</label>
            <Input
              id="login-email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </div>
          <div>
            <label className="ka-label" htmlFor="login-password">{t('login.password')}</label>
            <Input.Password
              id="login-password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onPressEnter={() => void submit()}
            />
          </div>
          <Checkbox checked={remember} onChange={(e) => setRemember(e.target.checked)}>
            {t('login.remember')}
          </Checkbox>
          <Button type="primary" className="ka-cta" loading={busy} onClick={() => void submit()} block>
            {t('login.signIn')}
          </Button>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('login.invitationOnly')}</Text>
        </Flex>
      </section>
      <section
        className="ka-login-hero"
        style={{ backgroundImage: `url(${asset('auth/featured-sc5.jpg')})` }}
        aria-hidden
      >
        <blockquote className="ka-login-quote">
          <p>{t('login.quote')}</p>
          <footer>{t('login.quoteAttribution')}</footer>
        </blockquote>
      </section>
    </div>
  );
}

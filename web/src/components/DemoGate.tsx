import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Button, Input, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { DemoBanner } from './DemoBanner';

/**
 * The PUBLIC DEMO front-door + banner host. Purely a marking/gating layer ON TOP of
 * the app — it does NOT replace Sanctum login or RLS (those still apply beneath).
 *
 * Server-driven (GET /api/demo/gate → { demo, open }):
 *   - demo=false          → render the app as-is (no banner, no gate) — local/CI/real-prod.
 *   - demo=true, open      → show the banner + the app.
 *   - demo=true, !open     → show the front-door; a correct shared code (checked
 *                            server-side, never shipped here) sets the gate cookie.
 * Fail-OPEN on a network/endpoint error: the gate is not real security, and a broken
 * gate endpoint must not lock the whole demo out — the real controls remain in force.
 */
type Gate = { demo: boolean; open: boolean };

async function fetchGate(): Promise<Gate> {
  try {
    const r = await fetch('/api/demo/gate', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!r.ok) return { demo: false, open: true };
    return (await r.json()) as Gate;
  } catch {
    return { demo: false, open: true };
  }
}

export function DemoGate({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const [gate, setGate] = useState<Gate | null>(null);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(false);

  useEffect(() => {
    void fetchGate().then(setGate);
  }, []);

  const showBanner = gate?.demo === true;
  useEffect(() => {
    document.documentElement.classList.toggle('ka-demo-banner-on', showBanner);
    return () => document.documentElement.classList.remove('ka-demo-banner-on');
  }, [showBanner]);

  if (gate === null) return <div className="ka-route-loading" aria-hidden />; // initial gate check

  if (!gate.demo || gate.open) {
    return (
      <>
        {showBanner && <DemoBanner />}
        {children}
      </>
    );
  }

  const submit = async () => {
    if (!code) return;
    setBusy(true);
    setError(false);
    try {
      const r = await fetch('/api/demo/gate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ code }),
      });
      if (r.ok) setGate({ demo: true, open: true });
      else setError(true);
    } catch {
      setError(true);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="ka-demo-gate">
      <div className="ka-demo-gate__card">
        <div className="ka-demo-gate__brand">{t('app.academy')}</div>
        <Typography.Title level={3} style={{ marginTop: 6, marginBottom: 4 }}>
          {t('demo.gate.title')}
        </Typography.Title>
        <Typography.Paragraph type="secondary" style={{ marginBottom: 18 }}>
          {t('demo.gate.subtitle')}
        </Typography.Paragraph>
        <Input.Password
          size="large"
          value={code}
          placeholder={t('demo.gate.placeholder')}
          onChange={(e) => {
            setCode(e.target.value);
            setError(false);
          }}
          onPressEnter={submit}
          status={error ? 'error' : undefined}
          autoFocus
          aria-label={t('demo.gate.codeLabel')}
        />
        {error && (
          <div className="ka-demo-gate__error" role="alert">
            {t('demo.gate.error')}
          </div>
        )}
        <Button type="primary" size="large" block loading={busy} disabled={!code} onClick={submit} style={{ marginTop: 14 }}>
          {t('demo.gate.enter')}
        </Button>
        <div className="ka-demo-gate__note">{t('demo.banner')}</div>
      </div>
    </div>
  );
}

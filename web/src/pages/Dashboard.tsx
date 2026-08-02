// S-UX1 — role-scoped landing, replacing the empty placeholder at `/`. Composed from
// EXISTING RLS-scoped endpoints (D-UX1.2: composition first). Each card is gated by the
// same permission as its underlying screen, so the dashboard never shows a number the
// caller could not open. Counts only — money formatting is S-UX2a's shared kit, not here.
import { useEffect, useState } from 'react';
import { Card, Col, Row, Spin, Statistic, Typography } from 'antd';
import { CircleAlert, FileSignature, GraduationCap, Link2, Scale, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { authFetch } from '../auth/session';
import { useIdentity } from '../auth/identity';
import { kaColors } from '../theme/theme';

interface Metric {
  key: string;
  labelKey: string;
  value: number;
  icon: React.ReactNode;
  to: string;
  alert?: boolean; // render the value in the alert colour (e.g. issuance gaps > 0)
}

const OPEN_CONSENT = new Set(['sent', 'viewed']);
const SETTLED_LINK = new Set(['paid', 'expired', 'void', 'cancelled']);

export function Dashboard() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { identity, has } = useIdentity();
  const [metrics, setMetrics] = useState<Metric[] | null>(null);

  useEffect(() => {
    if (!identity) return;
    let alive = true;

    const json = async (url: string): Promise<any | null> => {
      try {
        const r = await authFetch(url);
        return r.ok ? await r.json() : null;
      } catch {
        return null;
      }
    };

    async function load() {
      const out: Metric[] = [];

      if (has('enrolment.view')) {
        const d = await json('/api/enrolments');
        out.push({
          key: 'enrolments',
          labelKey: 'dashboard.enrolments',
          value: (d?.data ?? []).length,
          icon: <GraduationCap size={18} aria-hidden />,
          to: '/enrolments',
        });
      }
      if (has('consent.view')) {
        const d = await json('/api/consent-requests');
        out.push({
          key: 'consent',
          labelKey: 'dashboard.openConsent',
          value: (d?.data ?? []).filter((r: { status: string }) => OPEN_CONSENT.has(r.status)).length,
          icon: <FileSignature size={18} aria-hidden />,
          to: '/consents',
        });
      }
      if (identity?.role === 'guardian') {
        const d = await json('/api/my/payment-links');
        out.push({
          key: 'links',
          labelKey: 'dashboard.liveLinks',
          value: (d?.data ?? []).filter((r: { status: string }) => !SETTLED_LINK.has(r.status)).length,
          icon: <Link2 size={18} aria-hidden />,
          to: '/enrolments',
        });
      }
      if (has('audit.read')) {
        const d = await json('/api/reports/enrolment-pool');
        const gaps = (d?.issuance_gaps ?? []).length;
        out.push({
          key: 'pool',
          labelKey: 'dashboard.poolProgrammes',
          value: (d?.pool_by_programme ?? []).length,
          icon: <Users size={18} aria-hidden />,
          to: '/admin/enrolment-pool',
        });
        out.push({
          key: 'gaps',
          labelKey: 'dashboard.issuanceGaps',
          value: gaps,
          icon: <CircleAlert size={18} aria-hidden />,
          to: '/admin/enrolment-pool',
          alert: gaps > 0,
        });
      }
      if (has('finance.record')) {
        const d = await json('/api/reports/financial-integrity');
        out.push({
          key: 'orders',
          labelKey: 'dashboard.orders',
          value: (d?.orders ?? []).length,
          icon: <Scale size={18} aria-hidden />,
          to: '/admin/financial-integrity',
        });
      }

      if (alive) setMetrics(out);
    }

    void load();
    return () => {
      alive = false;
    };
  }, [identity, has]);

  return (
    <div style={{ maxWidth: 1100 }}>
      <Typography.Title level={3} style={{ marginTop: 0 }}>
        {t('dashboard.greeting', { name: identity?.name ?? '' })}
      </Typography.Title>
      <Typography.Paragraph type="secondary">{t('dashboard.subtitle')}</Typography.Paragraph>

      {metrics === null ? (
        <div style={{ padding: 48, textAlign: 'center' }}>
          <Spin />
        </div>
      ) : metrics.length === 0 ? (
        <Typography.Paragraph type="secondary">{t('dashboard.empty')}</Typography.Paragraph>
      ) : (
        <Row gutter={[16, 16]}>
          {metrics.map((m) => (
            <Col key={m.key} xs={24} sm={12} md={8}>
              <Card hoverable onClick={() => void navigate(m.to)} styles={{ body: { padding: 20 } }}>
                <Statistic
                  title={
                    <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
                      {m.icon}
                      {t(m.labelKey)}
                    </span>
                  }
                  value={m.value}
                  valueStyle={m.alert ? { color: kaColors.danger } : undefined}
                />
              </Card>
            </Col>
          ))}
        </Row>
      )}
    </div>
  );
}

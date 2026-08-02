// S04C STEP 1 — public self-registration (OD-23), the platform's first anonymous
// write. Trilingual; school picker is opt-in listed partners OR direct-to-academy
// (no free-text gap). A honeypot field + a server-issued form nonce (fill-time)
// confine bots. The response is constant-shape: on success the applicant sees an
// acknowledgement + opaque reference — never whether an account already existed.
import { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Card, DatePicker, Flex, Form, Input, Radio, Result, Select, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { asset } from '../assets';
import { LocaleSwitcher } from '../components/LocaleSwitcher';

const { Title, Paragraph } = Typography;

type Kind = 'student' | 'guardian';
type School = { id: number; name_en: string; name_tc: string; name_sc: string };

export function Register() {
  const { t, i18n } = useTranslation();
  const [form] = Form.useForm();
  const [kind, setKind] = useState<Kind>('guardian');
  const [schools, setSchools] = useState<School[]>([]);
  const [nonce, setNonce] = useState('');
  const [busy, setBusy] = useState(false);
  const [reference, setReference] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // fetch the listed schools + a fresh fill-time nonce on mount
  useEffect(() => {
    fetch('/api/register/schools', { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('load'))))
      .then((b: { schools: School[]; form_nonce: string }) => {
        setSchools(b.schools);
        setNonce(b.form_nonce);
      })
      .catch(() => setError(t('register.errorNetwork')));
  }, [t]);

  const schoolLabel = useMemo(
    () => (s: School) => (i18n.language === 'zh-TC' ? s.name_tc : i18n.language === 'zh-SC' ? s.name_sc : s.name_en),
    [i18n.language],
  );

  const submit = async (values: Record<string, unknown>) => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch('/api/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          kind,
          applicant_name: values.applicant_name,
          applicant_email: values.applicant_email,
          applicant_phone: values.applicant_phone || null,
          preferred_language: i18n.language,
          date_of_birth: kind === 'student' && values.date_of_birth
            ? (values.date_of_birth as { format: (f: string) => string }).format('YYYY-MM-DD')
            : null,
          school_id: values.school_id ?? null,
          counterpart_email: values.counterpart_email || null,
          counterpart_name: values.counterpart_name || null,
          website: values.website || '', // honeypot — real users leave this empty
          form_nonce: nonce,
        }),
      });
      if (res.status === 202) {
        const body = (await res.json()) as { reference: string };
        setReference(body.reference);
        return;
      }
      setError(t('register.errorValidation'));
    } catch {
      setError(t('register.errorNetwork'));
    } finally {
      setBusy(false);
    }
  };

  if (reference) {
    return (
      <div className="ka-register">
        <div style={{ position: 'fixed', top: 16, right: 16, zIndex: 10 }}><LocaleSwitcher /></div>
        <Card className="ka-register-card">
          <Result
            status="success"
            title={t('register.doneTitle')}
            subTitle={t('register.doneSubtitle', { reference })}
            extra={<Link to="/login"><Button type="primary">{t('register.toLogin')}</Button></Link>}
          />
        </Card>
      </div>
    );
  }

  return (
    <div className="ka-register">
      <Card className="ka-register-card">
        <Flex vertical gap={4} align="center" style={{ marginBottom: 16 }}>
          <img src={asset('brand/armour-academy-logo.webp')} alt={t('register.logoAlt')} style={{ height: 40 }} />
          <Title level={2} style={{ margin: 0 }}>{t('register.title')}</Title>
          <Paragraph type="secondary" style={{ margin: 0, textAlign: 'center' }}>{t('register.subtitle')}</Paragraph>
        </Flex>

        {error && <Alert type="error" showIcon message={error} style={{ marginBottom: 16 }} />}

        <Radio.Group
          value={kind}
          onChange={(e) => setKind(e.target.value as Kind)}
          optionType="button"
          buttonStyle="solid"
          style={{ marginBottom: 16, display: 'flex' }}
        >
          <Radio.Button value="guardian" style={{ flex: 1, textAlign: 'center' }}>{t('register.kindGuardian')}</Radio.Button>
          <Radio.Button value="student" style={{ flex: 1, textAlign: 'center' }}>{t('register.kindStudent')}</Radio.Button>
        </Radio.Group>

        <Form form={form} layout="vertical" onFinish={submit} requiredMark={false} disabled={busy}>
          <Form.Item name="applicant_name" label={t('register.name')} rules={[{ required: true, max: 120 }]}>
            <Input autoComplete="off" />
          </Form.Item>
          <Form.Item name="applicant_email" label={t('register.email')} rules={[{ required: true, type: 'email', max: 190 }]}>
            <Input autoComplete="off" />
          </Form.Item>
          <Form.Item name="applicant_phone" label={t('register.phone')}>
            <Input autoComplete="off" />
          </Form.Item>
          {kind === 'student' && (
            <Form.Item name="date_of_birth" label={t('register.dob')}>
              <DatePicker style={{ width: '100%' }} />
            </Form.Item>
          )}
          <Form.Item name="school_id" label={t('register.school')} help={t('register.schoolHelp')}>
            <Select allowClear placeholder={t('register.schoolDirect')} options={schools.map((s) => ({ value: s.id, label: schoolLabel(s) }))} />
          </Form.Item>
          <Form.Item name="counterpart_email" label={kind === 'guardian' ? t('register.childEmail') : t('register.guardianEmail')} rules={[{ type: 'email', max: 190 }]}>
            <Input autoComplete="off" />
          </Form.Item>
          <Form.Item name="counterpart_name" label={kind === 'guardian' ? t('register.childName') : t('register.guardianName')}>
            <Input autoComplete="off" />
          </Form.Item>

          {/* honeypot: off-screen, not tab-reachable; only a bot fills it */}
          <Form.Item name="website" style={{ position: 'absolute', left: '-9999px', height: 0, overflow: 'hidden' }} aria-hidden>
            <Input tabIndex={-1} autoComplete="off" />
          </Form.Item>

          <Button type="primary" htmlType="submit" block loading={busy}>{t('register.submit')}</Button>
        </Form>

        <Paragraph type="secondary" style={{ marginTop: 16, textAlign: 'center', fontSize: 13 }}>
          {t('register.approvalNote')}
        </Paragraph>
        <Paragraph style={{ textAlign: 'center', margin: 0 }}>
          <Link to="/login">{t('register.haveAccount')}</Link>
        </Paragraph>
      </Card>
    </div>
  );
}

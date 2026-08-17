// KAP-MKT-1 — the programme MARKETPLACE. A DS2-native card grid over the ANONYMOUS-safe catalogue
// (GET /programmes: published + marketing-complete only; no PII, no capacity, no enrolled flag — R-4).
// The R-2 button state is DERIVED, never a client flag: from the catalogue's open/closed status + the
// caller's OWN /api/enrolments (a student sees their own, a guardian their children's — enr_read).
// The Enroll PRESS is a GUARDIAN action reusing POST /my/enrolments → EnrolmentService::create verbatim
// (guardian-led per OD-23/OD-27; lands at Submitted → the guardian's consent task, ceremony untouched).
// A student sees the full browse + status but no Enroll button — an honest "your guardian enrols you".
import { useState } from 'react';
import { App, Button, Col, Modal, Row, Select, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { isGuardianActor } from '../nav';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { personName } from '../display/names';
import { SubPanel, EmptyState } from '@/ds2';

const { Title, Text, Paragraph } = Typography;

interface Tri { en: string; tc: string; sc: string }
interface ProgrammeCard {
  id: number; code: string; name_en: string; name_tc: string; name_sc: string;
  phase: string; status: 'open' | 'closed'; brand_color: string; banner_url: string | null;
  tagline: Tri; category: Tri; age_range: Tri; duration: Tri;
}
interface EnrolRow { student_id: number; programme_id: number; status: string; student_name: string | null }

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
  const [enrolFor, setEnrolFor] = useState<ProgrammeCard | null>(null);
  const [pick, setPick] = useState<number | undefined>(undefined);

  const rows = enrolments.data?.data ?? [];
  // Programme ids the caller (or their children) already hold a LIVE enrolment in.
  const enrolled = new Set(rows.filter((e) => !TERMINAL.has(e.status)).map((e) => e.programme_id));
  // A guardian's distinct children (from their own enrolments); used by the enrol picker.
  const children = Array.from(new Map(rows.map((e) => [e.student_id, e.student_name])).entries()).map(([id, name]) => ({ id, name }));

  const submit = async () => {
    if (!enrolFor || pick === undefined) return;
    const r = await mutate('/api/my/enrolments', { programme_id: enrolFor.id, student_id: pick });
    setEnrolFor(null);
    setPick(undefined);
    if (r.ok) { void message.success(t('marketplace.enrolled')); enrolments.reload(); }
    else void message.error(r.message ?? t('mutate.failed')); // closed/duplicate surfaced, server-authoritative
  };

  const button = (c: ProgrammeCard) => {
    if (enrolled.has(c.id)) return <Button size="small" disabled>{t('marketplace.enrolledState')}</Button>;
    if (c.status === 'closed') return <Button size="small" disabled>{t('marketplace.closed')}</Button>;
    if (isGuardian) return <Button size="small" type="primary" className="ka-cta" onClick={() => { setEnrolFor(c); setPick(undefined); }}>{t('marketplace.enrol')}</Button>;
    // Student on an open, not-enrolled programme — honest, never a dead button (guardian-led enrolment).
    return <Text type="secondary" style={{ fontSize: 12 }}>{t('marketplace.guardianEnrols')}</Text>;
  };

  return (
    <div style={{ maxWidth: 1100 }} data-density="product">
      <div style={{ marginBottom: 16 }}>
        <Title level={3} style={{ marginBottom: 0 }}>{t('marketplace.title')}</Title>
        <Paragraph type="secondary">{t('marketplace.subtitle')}</Paragraph>
      </div>
      <DataBoundary loading={catalogue.loading} error={catalogue.error} empty={(catalogue.data?.data.length ?? 0) === 0}>
        <Row gutter={[16, 16]} align="stretch">
          {(catalogue.data?.data ?? []).map((c) => (
            <Col key={c.id} xs={24} sm={12} md={8}>
              <SubPanel tone="neutral">
                {/* Banner: the scan-clean image; the brand_color band shows if it is absent or fails (§2.2 fallback — never a broken image). */}
                <div style={{ height: 120, borderRadius: 8, overflow: 'hidden', background: c.brand_color || 'var(--ka-muted)', marginBottom: 12 }}>
                  {c.banner_url && <img src={c.banner_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />}
                </div>
                <div style={{ display: 'inline-flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                  <Text strong>{progName(c, locale)}</Text>
                  <Tag color={c.status === 'open' ? 'green' : 'default'}>{t(`marketplace.status.${c.status}`)}</Tag>
                </div>
                <div style={{ marginTop: 4 }}><Text type="secondary">{triOf(c.tagline, locale)}</Text></div>
                <div style={{ marginTop: 4, fontSize: 12 }}><Text type="secondary">{triOf(c.category, locale)} · {triOf(c.age_range, locale)} · {triOf(c.duration, locale)}</Text></div>
                <div style={{ marginTop: 12 }}>{button(c)}</div>
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
        {enrolFor && (
          children.length === 0
            ? <EmptyState size="inline" message={t('marketplace.noChildren')} />
            : (
              <>
                <Paragraph type="secondary">{t('marketplace.enrolBody')}</Paragraph>
                <Select
                  style={{ width: '100%' }}
                  placeholder={t('marketplace.pickChild')}
                  value={pick}
                  onChange={setPick}
                  options={children.map((ch) => ({ value: ch.id, label: personName(ch.name), disabled: enrolled.has(enrolFor.id) && rows.some((e) => e.student_id === ch.id && e.programme_id === enrolFor.id && !TERMINAL.has(e.status)) }))}
                />
              </>
            )
        )}
      </Modal>
    </div>
  );
}

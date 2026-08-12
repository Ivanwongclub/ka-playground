// S-UX3-1 — the onboarding approval queue (operations.manage). OD-28: approving a PERSON
// (registration) and approving a RELATIONSHIP (link) are TWO separate decisions, shown
// distinctly. Every mutation is a deliberate two-step (confirm/reason modal → act) with a
// consequence-stating copy; server errors are surfaced; the queue refreshes after a mutate.
// The server re-checks authority on every call — this UI adds none.
import { useState } from 'react';
import { App, Button, Descriptions, Space, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { ReasonModal } from '../components/ReasonModal';
import { formatHkt } from '../display/date';
// DS2 (restyle rollout C5 — child-data tier: child-linked approval authority). ALLOWED adopter
// (import-guard). Container-framing only (Card→SubPanel); approve/decline/reject decision logic byte-identical.
import { SubPanel, EmptyState, UrgencyChip, approvalLevel, urgencyLabel } from '@/ds2';

const { Title, Paragraph } = Typography;

// R1-F1 (item 1): the identity fields beside the approve control — display-only, from the same row.
interface Account {
  id: string; kind: string; routing: string; applicant_name: string; age_days: number;
  applicant_email: string; applicant_phone: string | null; date_of_birth: string | null; preferred_language: string;
  counterpart_email: string | null; counterpart_name: string | null; reference: string;
  school_name_en: string | null; school_name_tc: string | null; school_name_sc: string | null;
}
interface Link { id: string; student_name: string | null; guardian_name: string | null; origin: string; age_days: number }
// S-FIX-UX-1 D6: a held guardian-link claim (held_links) — awaiting the counterpart to register.
// Read-only in this card (no actions); counterpart_name/expires_at are additive display fields.
interface Held { id: string; counterpart_email: string; counterpart_name: string | null; expires_at: string | null; age_days: number }
interface Queue { threshold_days: number; accounts: Account[]; links: Link[]; held: Held[] }

type Reasoned = { url: string; title: string; consequence?: string; okText: string } | null;

export function Approvals() {
  const { t, i18n } = useTranslation();
  const { modal, message } = App.useApp();
  const { data, loading, error, reload } = useResource<Queue>('/api/admin/onboarding-queue');
  const [reasoned, setReasoned] = useState<Reasoned>(null);

  // The one error surface: success → toast + refresh; failure → the server's message (403/422/409
  // shown, never swallowed), with a status fallback.
  const surface = (r: MutateResult) => {
    if (r.ok) {
      void message.success(t('mutate.success'));
      reload();
      return;
    }
    void message.error(
      r.message ??
        (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')),
    );
  };

  const confirmAct = (title: string, consequence: string, okText: string, url: string, danger = false) =>
    modal.confirm({
      title,
      content: consequence,
      okText,
      cancelText: t('common.cancel'),
      okButtonProps: { danger },
      onOk: async () => surface(await mutate(url)),
    });

  const accounts = data?.accounts ?? [];
  const links = data?.links ?? [];
  const held = data?.held ?? [];

  // R0-B4: approvals urgency from age_days vs the queue's threshold_days (no raw deadline in the payload) —
  // approvalLevel windows match approvalThresholds (overdue past threshold, due at it, soon within 2d).
  const threshold = data?.threshold_days ?? 0;
  const ageCell = (v: number) => {
    const lvl = approvalLevel(v, threshold);
    return (
      <Space>
        {t('approvals.ageDays', { n: v })}
        {lvl !== 'none' && <UrgencyChip level={lvl} label={urgencyLabel(lvl, threshold - v, t)} />}
      </Space>
    );
  };
  const rowUrg = (row: { age_days: number }) => {
    const lvl = approvalLevel(row.age_days, threshold);
    return lvl !== 'none' ? `ds2-urgent--${lvl}` : '';
  };

  // R1-F1 (item 1): the registration identity, rendered in the same view as the approve control.
  const schoolName = (r: Account) => (i18n.language === 'zh-TC' ? r.school_name_tc : i18n.language === 'zh-SC' ? r.school_name_sc : r.school_name_en) || r.school_name_en;
  const renderAccountDetail = (r: Account) => (
    <Descriptions size="small" column={1} bordered>
      <Descriptions.Item label={t('approvals.dEmail')}>{r.applicant_email}</Descriptions.Item>
      <Descriptions.Item label={t('approvals.dPhone')}>{r.applicant_phone || '—'}</Descriptions.Item>
      <Descriptions.Item label={t('approvals.dDob')}>{r.date_of_birth || '—'}</Descriptions.Item>
      <Descriptions.Item label={t('approvals.dLanguage')}>{r.preferred_language}</Descriptions.Item>
      <Descriptions.Item label={t('approvals.dRouting')}>{r.routing === 'school' ? (schoolName(r) || t('approvals.routeSchool')) : t('approvals.routeAcademy')}</Descriptions.Item>
      <Descriptions.Item label={t('approvals.dCounterpart')}>{[r.counterpart_name, r.counterpart_email].filter(Boolean).join(' · ') || '—'}</Descriptions.Item>
      <Descriptions.Item label={t('approvals.dReference')}>{r.reference}</Descriptions.Item>
    </Descriptions>
  );

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('approvals.title')}</Title>
        <Paragraph type="secondary">{t('approvals.subtitle')}</Paragraph>
      </div>

      <DataBoundary loading={loading} error={error}>
        <Space direction="vertical" size="large" style={{ width: '100%' }}>
        <SubPanel tone="neutral">
          <Title level={5} style={{ marginTop: 0 }}>{t('approvals.pendingRegistrations')}</Title>
          <Table<Account>
            rowKey="id"
            rowClassName={rowUrg}
            size="small"
            dataSource={accounts}
            pagination={false}
            expandable={{ expandedRowRender: renderAccountDetail }}
            locale={{ emptyText: <EmptyState size="inline" message={t('approvals.noneRegistrations')} /> }}
            columns={[
              { title: t('approvals.applicant'), dataIndex: 'applicant_name' },
              { title: t('approvals.kind'), dataIndex: 'kind', render: (v: string) => <Tag>{t(`role.${v}`)}</Tag> },
              { title: t('approvals.age'), dataIndex: 'age_days', render: (v: number) => ageCell(v) },
              {
                title: t('common.actions'), key: 'act',
                render: (_, r) => (
                  <Space>
                    <Button size="small" type="primary" onClick={() =>
                      confirmAct(
                        t('approvals.approveRegTitle'),
                        t('approvals.approveRegBody', { name: r.applicant_name }),
                        t('approvals.approve'),
                        `/api/admin/registration-requests/${r.id}/approve`,
                      )
                    }>{t('approvals.approve')}</Button>
                    <Button size="small" danger onClick={() => setReasoned({
                      url: `/api/admin/registration-requests/${r.id}/decline`,
                      title: t('approvals.declineTitle'),
                      okText: t('approvals.decline'),
                    })}>{t('approvals.decline')}</Button>
                  </Space>
                ),
              },
            ]}
          />
        </SubPanel>

        <SubPanel tone="neutral">
          <Title level={5} style={{ marginTop: 0 }}>{t('approvals.pendingLinks')}</Title>
          <Paragraph type="secondary" style={{ marginTop: -8 }}>{t('approvals.linksNote')}</Paragraph>
          <Table<Link>
            rowKey="id"
            rowClassName={rowUrg}
            size="small"
            dataSource={links}
            pagination={false}
            locale={{ emptyText: <EmptyState size="inline" message={t('approvals.noneLinks')} /> }}
            columns={[
              {
                title: t('approvals.relationship'), key: 'rel',
                render: (_, r) => t('approvals.link', { guardian: r.guardian_name ?? '—', student: r.student_name ?? '—' }),
              },
              { title: t('approvals.age'), dataIndex: 'age_days', render: (v: number) => ageCell(v) },
              {
                title: t('common.actions'), key: 'act',
                render: (_, r) => (
                  <Space>
                    {/* OD-28 sensitive act — the confirm copy STATES the access consequence. */}
                    <Button size="small" type="primary" onClick={() =>
                      confirmAct(
                        t('approvals.approveLinkTitle'),
                        t('approvals.approveLinkBody', { guardian: r.guardian_name ?? '—', student: r.student_name ?? '—' }),
                        t('approvals.approve'),
                        `/api/admin/guardian-links/${r.id}/approve`,
                      )
                    }>{t('approvals.approve')}</Button>
                    <Button size="small" danger onClick={() => setReasoned({
                      url: `/api/admin/guardian-links/${r.id}/reject`,
                      title: t('approvals.rejectLinkTitle'),
                      okText: t('approvals.reject'),
                    })}>{t('approvals.reject')}</Button>
                  </Space>
                ),
              },
            ]}
          />
        </SubPanel>

        {/* S-FIX-UX-1 D6: held guardian-link claims — READ-ONLY here (no actions on this card). */}
        <SubPanel tone="neutral">
          <Title level={5} style={{ marginTop: 0 }}>{t('approvals.pendingHeld')}</Title>
          <Paragraph type="secondary" style={{ marginTop: -8 }}>{t('approvals.heldNote')}</Paragraph>
          <Table<Held>
            rowKey="id"
            rowClassName={rowUrg}
            size="small"
            dataSource={held}
            pagination={false}
            locale={{ emptyText: <EmptyState size="inline" message={t('approvals.noneHeld')} /> }}
            columns={[
              { title: t('approvals.counterpart'), dataIndex: 'counterpart_name', render: (v: string | null) => v ?? '—' },
              { title: t('approvals.heldEmail'), dataIndex: 'counterpart_email' },
              { title: t('approvals.deadline'), dataIndex: 'expires_at', render: (v: string | null) => (v ? formatHkt(v, i18n.language) : '—') },
              { title: t('approvals.age'), dataIndex: 'age_days', render: (v: number) => ageCell(v) },
            ]}
          />
        </SubPanel>
        </Space>
      </DataBoundary>

      <ReasonModal
        open={reasoned !== null}
        title={reasoned?.title ?? ''}
        consequence={reasoned?.consequence}
        okText={reasoned?.okText ?? ''}
        onOk={async (reason) => {
          const target = reasoned;
          setReasoned(null);
          if (target) surface(await mutate(target.url, { reason }));
        }}
        onCancel={() => setReasoned(null)}
      />
    </Space>
  );
}

// S-UX3-1 — the onboarding approval queue (operations.manage). OD-28: approving a PERSON
// (registration) and approving a RELATIONSHIP (link) are TWO separate decisions, shown
// distinctly. Every mutation is a deliberate two-step (confirm/reason modal → act) with a
// consequence-stating copy; server errors are surfaced; the queue refreshes after a mutate.
// The server re-checks authority on every call — this UI adds none.
import { useState } from 'react';
import { App, Button, Space, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { ReasonModal } from '../components/ReasonModal';
// DS2 (restyle rollout C5 — child-data tier: child-linked approval authority). ALLOWED adopter
// (import-guard). Container-framing only (Card→SubPanel); approve/decline/reject decision logic byte-identical.
import { SubPanel } from '@/ds2';

const { Title, Paragraph } = Typography;

interface Account { id: string; kind: string; routing: string; applicant_name: string; age_days: number }
interface Link { id: string; student_name: string | null; guardian_name: string | null; origin: string; age_days: number }
interface Queue { threshold_days: number; accounts: Account[]; links: Link[]; held: unknown[] }

type Reasoned = { url: string; title: string; consequence?: string; okText: string } | null;

export function Approvals() {
  const { t } = useTranslation();
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
            size="small"
            dataSource={accounts}
            pagination={false}
            locale={{ emptyText: t('approvals.noneRegistrations') }}
            columns={[
              { title: t('approvals.applicant'), dataIndex: 'applicant_name' },
              { title: t('approvals.kind'), dataIndex: 'kind', render: (v: string) => <Tag>{t(`role.${v}`)}</Tag> },
              { title: t('approvals.age'), dataIndex: 'age_days', render: (v: number) => t('approvals.ageDays', { n: v }) },
              {
                title: '', key: 'act',
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
            size="small"
            dataSource={links}
            pagination={false}
            locale={{ emptyText: t('approvals.noneLinks') }}
            columns={[
              {
                title: t('approvals.relationship'), key: 'rel',
                render: (_, r) => t('approvals.link', { guardian: r.guardian_name ?? '—', student: r.student_name ?? '—' }),
              },
              { title: t('approvals.age'), dataIndex: 'age_days', render: (v: number) => t('approvals.ageDays', { n: v }) },
              {
                title: '', key: 'act',
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

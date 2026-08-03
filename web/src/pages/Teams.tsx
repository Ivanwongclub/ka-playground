// S-UX3-3a STEP 2 — the ops-facing 成團 view. A work queue of `submitted` teams (RLS-shaped
// GET /teams, B1 names), a per-team detail drawer whose member consent status comes from the
// STEP-1 endpoint (/teams/{team}/consent-status — booleans/counts only, no guardian identity),
// and the 成團 confirm: the button is SHOWN and ENABLED (never client-disabled); the server's
// FOR SHARE re-check is the authority and every refusal is rendered (S-UX3-1 error surface).
//
// The count "X of N signed" is the PRIMARY consent signal (STEP-1 review note 1); the coarse
// `blocker` word is a subordinate hint. This screen renders ONLY the STEP-1 allowlist keys — it
// never expands `blocker` into a per-guardian breakdown (that would reintroduce the leak surface).
import { useState } from 'react';
import { App, Button, Card, Drawer, Space, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { StatusTag } from '../display/status';
import { programmeName, personName } from '../display/names';

const { Title, Paragraph, Text } = Typography;

interface TeamRow {
  id: string;
  name: string;
  status: string;
  created_by_name: string | null;
  member_count: number;
  programme_name_en?: string | null;
  programme_name_tc?: string | null;
  programme_name_sc?: string | null;
  category_name_en?: string | null;
  category_name_tc?: string | null;
  category_name_sc?: string | null;
}

interface ConsentMember {
  student_id: number;
  student_name: string | null;
  satisfied: boolean;
  signed_count: number;
  guardian_count: number;
  blocker: string | null;
}

interface ConsentStatus {
  team_id: string;
  mode: 'any-one' | 'requires_all';
  all_satisfied: boolean;
  blocking_count: number;
  members: ConsentMember[];
}

/** Localised category/lobby name from the S-UX2b triple; falls back across languages, then —. */
function categoryName(r: TeamRow, locale: KaLocale): string {
  const byLocale = locale === 'zh-TC' ? r.category_name_tc : locale === 'zh-SC' ? r.category_name_sc : r.category_name_en;
  return byLocale ?? r.category_name_en ?? r.category_name_tc ?? r.category_name_sc ?? '—';
}

export function Teams() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const { data, loading, error, reload } = useResource<{ data: TeamRow[] }>('/api/teams');
  const [open, setOpen] = useState<TeamRow | null>(null);

  // Detail drawer: per-member consent from the STEP-1 endpoint, fetched only while a team is open.
  const detail = useResource<ConsentStatus>(open ? `/api/teams/${open.id}/consent-status` : '');

  const queue = (data?.data ?? []).filter((r) => r.status === 'submitted');

  const surface = (r: MutateResult) => {
    if (r.ok) {
      void message.success(t('teams.confirmed'));
      setOpen(null);
      reload();
      return;
    }
    // The server owns 成團: 409 not-submitted / no-capacity, 422 no-deadline / no-members /
    // consent-unsatisfied, 403 authority — each surfaced verbatim, never swallowed.
    void message.error(
      r.message ??
        (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')),
    );
  };

  // 成團 confirm — enabled + advisory. The advisory is appended ONLY when the detail read shows a
  // blocker; the button never blocks the click (the server is the gate).
  const confirm = (team: TeamRow, blocking: number) =>
    modal.confirm({
      title: t('teams.confirmTitle', { team: team.name }),
      content: (
        <Space direction="vertical" size="small">
          <Text>{t('teams.confirmBody', { n: team.member_count })}</Text>
          {blocking > 0 && (
            <Text type="warning">{t('teams.confirmAdvisory', { m: blocking })}</Text>
          )}
        </Space>
      ),
      okText: t('teams.confirmCta'),
      cancelText: t('common.cancel'),
      onOk: async () => surface(await mutate(`/api/teams/${team.id}/confirm`)),
    });

  const members = detail.data?.members ?? [];

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('teams.title')}</Title>
        <Paragraph type="secondary">{t('teams.subtitle')}</Paragraph>
      </div>

      <DataBoundary loading={loading} error={error} empty={queue.length === 0}>
        <Card title={t('teams.queueTitle')}>
          <Table<TeamRow>
            rowKey="id"
            size="small"
            dataSource={queue}
            pagination={false}
            columns={[
              { title: t('teams.name'), dataIndex: 'name' },
              { title: t('teams.programme'), key: 'programme', render: (_, r) => programmeName(r, locale) },
              { title: t('teams.category'), key: 'category', render: (_, r) => categoryName(r, locale) },
              { title: t('teams.createdBy'), dataIndex: 'created_by_name', render: (v: string | null) => personName(v) },
              { title: t('teams.members'), dataIndex: 'member_count', align: 'right' as const },
              { title: '', dataIndex: 'status', render: (s: string) => <StatusTag domain="teamStatus" value={s} /> },
              {
                title: '', key: 'act',
                render: (_, r) => (
                  <Button size="small" onClick={() => setOpen(r)}>{t('teams.review')}</Button>
                ),
              },
            ]}
          />
        </Card>
      </DataBoundary>

      <Drawer
        width={560}
        open={open !== null}
        onClose={() => setOpen(null)}
        title={open ? t('teams.detailTitle', { team: open.name }) : ''}
        extra={
          open && (
            <Button type="primary" onClick={() => confirm(open, detail.data?.blocking_count ?? 0)}>
              {t('teams.confirmCta')}
            </Button>
          )
        }
      >
        {open && (
          <DataBoundary loading={detail.loading} error={detail.error}>
            <Space direction="vertical" size="middle" style={{ width: '100%' }}>
              <Space>
                <Text type="secondary">{t('teams.consentMode')}:</Text>
                <Tag>{t(`teams.mode.${detail.data?.mode ?? 'any-one'}`)}</Tag>
                {detail.data && detail.data.blocking_count > 0 ? (
                  <Text type="warning">{t('teams.blockingCount', { m: detail.data.blocking_count })}</Text>
                ) : (
                  <Text type="success">{t('teams.allSatisfied')}</Text>
                )}
              </Space>

              <Table<ConsentMember>
                rowKey="student_id"
                size="small"
                dataSource={members}
                pagination={false}
                columns={[
                  { title: t('teams.member'), dataIndex: 'student_name', render: (v: string | null) => personName(v) },
                  {
                    // Count is the PRIMARY signal — "X of N signed" — for requires_all teams.
                    title: t('teams.guardiansSigned'), key: 'signed',
                    render: (_, m) => (
                      <Text>{t('teams.signedOf', { signed: m.signed_count, total: m.guardian_count })}</Text>
                    ),
                  },
                  {
                    title: '', key: 'consent',
                    render: (_, m) =>
                      m.satisfied ? (
                        <Tag color="success">{t('teams.satisfied')}</Tag>
                      ) : (
                        // Subordinate coarse hint beside the count — never a per-guardian breakdown.
                        <Tag color="warning">{m.blocker ? t(`teams.blocker.${m.blocker}`) : t('teams.notSatisfied')}</Tag>
                      ),
                  },
                ]}
              />
            </Space>
          </DataBoundary>
        )}
      </Drawer>
    </Space>
  );
}

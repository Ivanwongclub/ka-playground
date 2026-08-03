// S-UX3-3a STEP 2+3 — the ops-facing 成團 view. A work queue of `submitted` teams (RLS-shaped
// GET /teams, B1 names), a per-team detail drawer with two tabs:
//  · Consent status (STEP 2) — per-member booleans/counts from /teams/{team}/consent-status (no
//    guardian identity); "X of N signed" is the PRIMARY signal, the coarse `blocker` a subordinate
//    hint; renders ONLY the STEP-1 allowlist keys, never a per-guardian breakdown.
//  · Roles & tenure (STEP 3) — /teams/{team}/roles (B3, member-readable, resolved within RLS): the
//    current holder per role + past (ended) tenures; assignRole records a rotation with a
//    tenure-change confirm. One active holder per role (DB-enforced); the read never shows two open.
// 成團 confirm and assignRole are both SHOWN + ENABLED; the server is the authority and every refusal
// is rendered (S-UX3-1 error surface).
import { useState } from 'react';
import { Alert, App, Button, Card, Drawer, List, Modal, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { StatusTag } from '../display/status';
import { programmeName, personName } from '../display/names';
import { formatHkt } from '../display/date';

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

interface TenureHolder {
  student_id: number;
  student_name: string | null;
  started_at: string;
  ended_at?: string | null;
  ended_reason?: string | null;
}

interface RoleRow {
  role_id: string;
  name_en: string;
  name_tc: string;
  name_sc: string;
  mandatory: boolean;
  current: TenureHolder | null;
  past: TenureHolder[];
}

interface TeamMemberRow {
  enrolment_id: string;
  student_id: number;
  student_name: string | null;
}

interface RolesData {
  team_id: string;
  roles: RoleRow[];
  members: TeamMemberRow[];
}

/** Localised name from a *_en/_tc/_sc triple; falls back across languages, then —. */
function triName(en?: string | null, tc?: string | null, sc?: string | null, locale?: KaLocale): string {
  const byLocale = locale === 'zh-TC' ? tc : locale === 'zh-SC' ? sc : en;
  return byLocale ?? en ?? tc ?? sc ?? '—';
}

export function Teams() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const { data, loading, error, reload } = useResource<{ data: TeamRow[] }>('/api/teams');
  const [open, setOpen] = useState<TeamRow | null>(null);

  // Both detail reads are fetched only while a team is open.
  const detail = useResource<ConsentStatus>(open ? `/api/teams/${open.id}/consent-status` : '');
  const roles = useResource<RolesData>(open ? `/api/teams/${open.id}/roles` : '');

  // assignRole modal state
  const [assign, setAssign] = useState<RoleRow | null>(null);
  const [pick, setPick] = useState<string | undefined>(undefined);

  const queue = (data?.data ?? []).filter((r) => r.status === 'submitted');

  const surfaceError = (r: MutateResult) =>
    void message.error(
      r.message ??
        (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')),
    );

  // 成團 success closes the drawer + refreshes the queue.
  const surfaceConfirm = (r: MutateResult) => {
    if (r.ok) {
      void message.success(t('teams.confirmed'));
      setOpen(null);
      reload();
      return;
    }
    surfaceError(r);
  };

  // 成團 confirm — enabled + advisory; the server FOR SHARE re-check is the gate.
  const confirm = (team: TeamRow, blocking: number) =>
    modal.confirm({
      title: t('teams.confirmTitle', { team: team.name }),
      content: (
        <Space direction="vertical" size="small">
          <Text>{t('teams.confirmBody', { n: team.member_count })}</Text>
          {blocking > 0 && <Text type="warning">{t('teams.confirmAdvisory', { m: blocking })}</Text>}
        </Space>
      ),
      okText: t('teams.confirmCta'),
      cancelText: t('common.cancel'),
      onOk: async () => surfaceConfirm(await mutate(`/api/teams/${team.id}/confirm`)),
    });

  // assignRole — records a tenure rotation; refusals (403 authority, 409 already-holds / wrong state,
  // 422 not-a-member / wrong programme) rendered; roles refresh on success (drawer stays open).
  const doAssign = async () => {
    if (!open || !assign || !pick) return;
    const r = await mutate(`/api/teams/${open.id}/roles`, { enrolment_id: pick, role_id: assign.role_id });
    if (r.ok) {
      void message.success(t('teams.roleAssigned'));
      setAssign(null);
      setPick(undefined);
      roles.reload();
    } else {
      surfaceError(r);
    }
  };

  const members = detail.data?.members ?? [];
  const pickName = (id?: string) => personName(roles.data?.members.find((m) => m.enrolment_id === id)?.student_name);

  const consentTab = (
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
              title: t('teams.guardiansSigned'), key: 'signed',
              render: (_, m) => <Text>{t('teams.signedOf', { signed: m.signed_count, total: m.guardian_count })}</Text>,
            },
            {
              title: '', key: 'consent',
              render: (_, m) =>
                m.satisfied ? (
                  <Tag color="success">{t('teams.satisfied')}</Tag>
                ) : (
                  <Tag color="warning">{m.blocker ? t(`teams.blocker.${m.blocker}`) : t('teams.notSatisfied')}</Tag>
                ),
            },
          ]}
        />
      </Space>
    </DataBoundary>
  );

  const rolesTab = (
    <DataBoundary loading={roles.loading} error={roles.error} empty={(roles.data?.roles.length ?? 0) === 0}>
      <List<RoleRow>
        itemLayout="vertical"
        size="small"
        dataSource={roles.data?.roles ?? []}
        renderItem={(role) => (
          <List.Item
            key={role.role_id}
            actions={[
              <Button key="assign" size="small" onClick={() => { setAssign(role); setPick(undefined); }}>
                {t('teams.roleAssign')}
              </Button>,
            ]}
          >
            <List.Item.Meta
              title={
                <Space>
                  <span>{triName(role.name_en, role.name_tc, role.name_sc, locale)}</span>
                  {role.mandatory && <Tag color="gold">{t('teams.roleMandatory')}</Tag>}
                </Space>
              }
              description={
                role.current ? (
                  <Text strong>{t('teams.roleCurrent')}: {personName(role.current.student_name)}</Text>
                ) : (
                  <Text type="secondary">{t('teams.roleVacant')}</Text>
                )
              }
            />
            {role.past.length > 0 && (
              <div style={{ marginTop: 4 }}>
                <Text type="secondary" style={{ fontSize: 12 }}>{t('teams.rolePast')}:</Text>
                {role.past.map((p, i) => (
                  <div key={i} style={{ fontSize: 12, opacity: 0.75 }}>
                    {personName(p.student_name)} · {formatHkt(p.started_at, locale)} → {formatHkt(p.ended_at, locale)}
                  </div>
                ))}
              </div>
            )}
          </List.Item>
        )}
      />
    </DataBoundary>
  );

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
              { title: t('teams.category'), key: 'category', render: (_, r) => triName(r.category_name_en, r.category_name_tc, r.category_name_sc, locale) },
              { title: t('teams.createdBy'), dataIndex: 'created_by_name', render: (v: string | null) => personName(v) },
              { title: t('teams.members'), dataIndex: 'member_count', align: 'right' as const },
              { title: '', dataIndex: 'status', render: (s: string) => <StatusTag domain="teamStatus" value={s} /> },
              {
                title: '', key: 'act',
                render: (_, r) => <Button size="small" onClick={() => setOpen(r)}>{t('teams.review')}</Button>,
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
          <Tabs
            items={[
              { key: 'consent', label: t('teams.tabConsent'), children: consentTab },
              { key: 'roles', label: t('teams.rolesTitle'), children: rolesTab },
            ]}
          />
        )}
      </Drawer>

      {/* assignRole — tenure-change confirm: a controlled member picker + consequence copy. The server
          is authoritative; refusals are rendered. (A plain Modal, not modal.confirm, so the Select is
          controllable.) */}
      <Modal
        open={assign !== null}
        title={assign ? t('teams.roleAssignTitle', { role: triName(assign.name_en, assign.name_tc, assign.name_sc, locale) }) : ''}
        okText={t('teams.roleAssignCta')}
        cancelText={t('common.cancel')}
        okButtonProps={{ disabled: !pick }}
        onOk={doAssign}
        onCancel={() => { setAssign(null); setPick(undefined); }}
      >
        {assign && (
          <>
            <Select
              style={{ width: '100%' }}
              placeholder={t('teams.rolePickMember')}
              value={pick}
              onChange={setPick}
              options={(roles.data?.members ?? []).map((m) => ({ value: m.enrolment_id, label: personName(m.student_name) }))}
            />
            {pick && (
              <Alert
                type="warning"
                showIcon
                style={{ marginTop: 12 }}
                message={
                  assign.current
                    ? t('teams.roleAssignBody', {
                        role: triName(assign.name_en, assign.name_tc, assign.name_sc, locale),
                        name: pickName(pick),
                        current: personName(assign.current.student_name),
                      })
                    : t('teams.roleAssignBodyNew', {
                        role: triName(assign.name_en, assign.name_tc, assign.name_sc, locale),
                        name: pickName(pick),
                      })
                }
              />
            )}
          </>
        )}
      </Modal>
    </Space>
  );
}

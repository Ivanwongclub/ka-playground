// S-UX3-3b STEP 2 — the STUDENT team-formation surface (/my/team), distinct from the ops /team.
// A student browses lobbies, creates or joins a forming team, sees their team's roster (B2: names +
// role + count), sees their OWN consent advisory (the self-consent read — UNMISSABLE so the student-
// side dead-loop is prevented), and the submitter submits (forming → submitted). v1 = NO leave.
//
// "My team" is detected by member_count >= 1 in the RLS-shaped GET /teams: a student sees only their own
// team_members row (tm_read), so a team where the count is >=1 is one they are IN; a joinable lobby team
// reads 0. This rides on the tm_read co-member wall (same dependency as the B2 names/count split) — if
// tm_read is ever widened, revisit this split.
import { useState } from 'react';
import { Alert, App, Button, Input, List, Select, Space, Tag, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate, type MutateResult } from '../api/mutate';
import { StatusTag } from '../display/status';
import { programmeName, personName } from '../display/names';
// DS2 (restyle rollout C4 — child-data tier). StudentTeam.tsx joins the ALLOWED @/ds2 adopters
// (import-guard). Container-framing only (Card→SubPanel); formation/join/submit handlers byte-identical.
import { SubPanel, EmptyState, WizardRail } from '@/ds2';

const { Title, Paragraph, Text } = Typography;

interface TeamRow {
  id: string;
  programme_id: number;
  name: string;
  status: string;
  member_count: number;
  programme_name_en?: string | null;
  programme_name_tc?: string | null;
  programme_name_sc?: string | null;
  category_id?: string | null;
}
interface EnrolRow { id: string; programme_id: number; status: string; programme_name_en?: string | null; programme_name_tc?: string | null; programme_name_sc?: string | null }
interface RosterMember { student_id: number; student_name: string | null; role: { name_en: string; name_tc: string; name_sc: string } | null }
interface Roster { team_id: string; member_count: number; members: RosterMember[] | null }
interface Lobby { id: string; name_en: string; name_tc: string; name_sc: string; school_bound: boolean; eligible: boolean }

function tri(o: { name_en: string; name_tc: string; name_sc: string } | null, locale: KaLocale): string {
  if (!o) return '—';
  return (locale === 'zh-TC' ? o.name_tc : locale === 'zh-SC' ? o.name_sc : o.name_en) || o.name_en;
}

export function StudentTeam() {
  const { t } = useTranslation();
  const teams = useResource<{ data: TeamRow[] }>('/api/teams');
  const enrolments = useResource<{ data: EnrolRow[] }>('/api/enrolments');

  const reloadAll = () => { teams.reload(); enrolments.reload(); };

  const all = teams.data?.data ?? [];
  const myTeams = all.filter((x) => x.member_count >= 1);
  const joinable = all.filter((x) => x.member_count === 0 && x.status === 'forming');
  const pooled = (enrolments.data?.data ?? []).filter((e) => e.status === 'in_pool');

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('studentTeam.title')}</Title>
        <Paragraph type="secondary">{t('studentTeam.subtitle')}</Paragraph>
      </div>

      <DataBoundary loading={teams.loading || enrolments.loading} error={teams.error || enrolments.error}>
        {myTeams.map((team) => <MyTeamCard key={team.id} team={team} onChange={reloadAll} />)}

        {/* Form-or-join is offered for each pooled (consented, unteamed) enrolment. */}
        {pooled.length === 0 && myTeams.length === 0 && (
          <EmptyState size="page" message={t('studentTeam.noPool')} />
        )}
        {pooled.map((enr) => (
          <FormOrJoin
            key={enr.id}
            enrolment={enr}
            joinable={joinable.filter((j) => j.programme_id === enr.programme_id)}
            onChange={reloadAll}
          />
        ))}
      </DataBoundary>
    </Space>
  );
}

/** The student's own team: roster (B2), consent advisory (self-read), submit (submitter-only, server-enforced). */
function MyTeamCard({ team, onChange }: { team: TeamRow; onChange: () => void }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { modal, message } = App.useApp();
  const roster = useResource<Roster>(`/api/teams/${team.id}/members`);
  const consent = useResource<{ satisfied: boolean }>(`/api/my/consent-status?programme_id=${team.programme_id}`);
  // R1-S2 B3: the Activity Tracker rail — the additive member-readable gates read ({stage, passed} booleans).
  const tracker = useResource<{ stages: { stage: string; passed: boolean }[] }>(`/api/teams/${team.id}/tracker`);
  // S-MENTOR-1 (ruling 2): the team's mentor name(s) — the names-only elevation read (empty when none/config-off).
  const teachers = useResource<{ teachers: { teacher_id: number; teacher_name: string | null }[] }>(`/api/teams/${team.id}/teachers`);

  const surface = (r: MutateResult) => {
    if (r.ok) { void message.success(t('studentTeam.submitted')); onChange(); return; }
    // submit refusals rendered (shown-not-hidden): 403 submitter-only, 409 wrong-state.
    void message.error(r.message ?? (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')));
  };

  const submit = () =>
    modal.confirm({
      title: t('studentTeam.submitTitle', { team: team.name }),
      content: t('studentTeam.submitBody'),
      okText: t('studentTeam.submit'),
      cancelText: t('common.cancel'),
      onOk: async () => surface(await mutate(`/api/teams/${team.id}/submit`)),
    });

  const satisfied = consent.data?.satisfied;

  return (
    <SubPanel tone="neutral">
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
        <Title level={5} style={{ margin: 0 }}>{team.name}</Title>
        <StatusTag domain="teamStatus" value={team.status} />
        <span style={{ flex: 1 }} />
        <Text type="secondary">{programmeName(team, locale)}</Text>
      </div>
      <Space direction="vertical" size="middle" style={{ width: '100%' }}>
        {/* UNMISSABLE consent advisory — prevents the student-side dead-loop at Team Formation. */}
        <DataBoundary loading={consent.loading} error={consent.error}>
          {satisfied === false && (
            <Alert
              type="error" showIcon
              message={t('studentTeam.consentNotSatisfiedTitle')}
              description={t('studentTeam.consentNotSatisfied')}
            />
          )}
          {satisfied === true && (
            <Alert type="success" showIcon message={t('studentTeam.consentSatisfied')} />
          )}
        </DataBoundary>

        {/* roster — names + role + count, from B2 (elevated). Never a teammate's consent. */}
        <DataBoundary loading={roster.loading} error={roster.error}>
          <div>
            <Text strong>{t('studentTeam.roster')}</Text>{' '}
            <Text type="secondary">({roster.data?.member_count ?? 0})</Text>
            <List
              size="small"
              dataSource={roster.data?.members ?? []}
              renderItem={(m) => (
                <List.Item>
                  <Space>
                    <span>{personName(m.student_name)}</span>
                    {m.role && <Tag color="gold">{tri(m.role, locale)}</Tag>}
                  </Space>
                </List.Item>
              )}
            />
          </div>
        </DataBoundary>

        {/* S-MENTOR-1 (ruling 2): the team's mentor(s), when the programme enables the mentor view. */}
        {(teachers.data?.teachers.length ?? 0) > 0 && (
          <div><Text type="secondary">{t('studentTeam.mentor')}: </Text><Text strong>{(teachers.data?.teachers ?? []).map((tt) => personName(tt.teacher_name)).join(', ')}</Text></div>
        )}

        {/* R1-S2 B3: the Activity Tracker rail (Plan · Design · Learn · Pitch · Launch) — passed stages
            'done', the rest pending. Display-only (no onStep); reads {stage, passed} booleans. */}
        <DataBoundary loading={tracker.loading} error={tracker.error}>
          <WizardRail
            phases={[{
              title: t('tracker.title'),
              steps: (tracker.data?.stages ?? []).map((g) => ({ label: t(`tracker.stage${g.stage}`), state: g.passed ? 'done' : 'todo' })),
            }]}
          />
        </DataBoundary>

        {/* AL-7: the header StatusTag pill already carries the team's state — the forming branch keeps its
            submit action, but the non-forming branch's floating status text is REMOVED (it was a duplicate). */}
        {team.status === 'forming' && (
          <div>
            {/* Submit is SHOWN to every member; the server enforces submitter-only (403 rendered). */}
            <Button type="primary" onClick={submit}>{t('studentTeam.submit')}</Button>
            <Paragraph type="secondary" style={{ fontSize: 12, marginTop: 8, marginBottom: 0 }}>
              {t('studentTeam.leaveDisabled')}
            </Paragraph>
          </div>
        )}
      </Space>
    </SubPanel>
  );
}

/** Per pooled enrolment: join an existing forming team (count only), or create a new one. */
function FormOrJoin({ enrolment, joinable, onChange }: { enrolment: EnrolRow; joinable: TeamRow[]; onChange: () => void }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { message } = App.useApp();
  const lobbies = useResource<{ data: Lobby[] }>(`/api/programmes/${enrolment.programme_id}/lobbies`);
  const [lobbyId, setLobbyId] = useState<string | undefined>(undefined);
  const [name, setName] = useState('');

  const surface = (r: MutateResult, okKey: string) => {
    if (r.ok) { void message.success(t(okKey)); onChange(); return; }
    void message.error(r.message ?? (r.status === 403 ? t('mutate.forbidden') : r.status === 0 ? t('mutate.network') : t('mutate.failed')));
  };

  const join = async (teamId: string) => surface(await mutate(`/api/teams/${teamId}/join`), 'studentTeam.joinedOk');
  const create = async () => {
    if (!lobbyId || name.trim().length < 2) return;
    const r = await mutate('/api/my/teams', { programme_id: enrolment.programme_id, category_id: lobbyId, name: name.trim() });
    if (r.ok) { setName(''); setLobbyId(undefined); }
    surface(r, 'studentTeam.createdOk');
  };

  const eligibleLobbies = (lobbies.data?.data ?? []).filter((l) => l.eligible);

  return (
    <SubPanel tone="neutral">
      <Title level={5} style={{ marginTop: 0 }}>{t('studentTeam.formOrJoin', { programme: programmeName(enrolment, locale) })}</Title>
      <Space direction="vertical" size="middle" style={{ width: '100%' }}>
        {joinable.length > 0 && (
          <div>
            <Text strong>{t('studentTeam.joinTitle')}</Text>
            <List
              size="small"
              dataSource={joinable}
              renderItem={(team) => (
                <List.Item actions={[<Button key="j" size="small" onClick={() => void join(team.id)}>{t('studentTeam.join')}</Button>]}>
                  <Space><span>{team.name}</span><JoinableCount teamId={team.id} /></Space>
                </List.Item>
              )}
            />
          </div>
        )}

        <div>
          <Text strong>{t('studentTeam.createTitle')}</Text>
          <DataBoundary loading={lobbies.loading} error={lobbies.error}>
            <Space direction="vertical" style={{ width: '100%', marginTop: 8 }}>
              <Select
                style={{ width: '100%', maxWidth: 360 }}
                placeholder={t('studentTeam.createLobby')}
                value={lobbyId}
                onChange={setLobbyId}
                options={eligibleLobbies.map((l) => ({
                  value: l.id,
                  // S-FIX-UX-1 D4: trilingual lobby name via tri(); school-bound shown as a Tooltip-ed marker.
                  label: (
                    <span>
                      {tri(l, locale)}
                      {l.school_bound && <> <Tooltip title={t('studentTeam.schoolBoundHint')}>★</Tooltip></>}
                    </span>
                  ),
                }))}
              />
              <Input
                style={{ maxWidth: 360 }}
                placeholder={t('studentTeam.createName')}
                value={name}
                onChange={(e) => setName(e.target.value)}
                maxLength={80}
              />
              <Button type="primary" disabled={!lobbyId || name.trim().length < 2} onClick={() => void create()}>
                {t('studentTeam.createCta')}
              </Button>
            </Space>
          </DataBoundary>
        </div>
      </Space>
    </SubPanel>
  );
}

/** A joinable team's COUNT ONLY (B2 count-only branch — never teammate names pre-join). */
function JoinableCount({ teamId }: { teamId: string }) {
  const { t } = useTranslation();
  const roster = useResource<Roster>(`/api/teams/${teamId}/members`);
  if (roster.loading || roster.data == null) return null;
  return <Tag>{t('studentTeam.members', { n: roster.data.member_count })}</Tag>;
}

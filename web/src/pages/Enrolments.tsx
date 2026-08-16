// S04A step 6: E5 timeline reshaped for team-based capacity — the pool is a
// state; each enrolment renders its journey with the current stage highlighted.
// S-UX2a: names (S-UX2b fields) instead of raw FK ids; shared fetch convention.
import { Space, Table, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { useIdentity } from '../auth/identity';
import { isStudentActor } from '../nav';
import { programmeName, personName } from '../display/names';
import { SubPanel, StateBadge, WizardRail } from '@/ds2'; // DS2 rollout D1 + R1-S2 B1 (journey → WizardRail)

const { Title, Paragraph } = Typography;

interface Row {
  id: string;
  programme_id: number;
  student_id: number;
  acting_guardian_id: number;
  status: string;
  created_at: string;
  programme_name_en: string | null;
  programme_name_tc: string | null;
  programme_name_sc: string | null;
  student_name: string | null;
  acting_guardian: string | null;
}

const JOURNEY = ['submitted', 'pending_consent', 'in_pool', 'teamed', 'confirmed', 'active', 'completed'];
const TERMINAL_BAD = ['withdrawn', 'released'];
// R1-S2 B1: per-stage deep-link targets (student context). A stage with no entry is not clickable.
const DEEP: Record<string, string> = { pending_consent: '/consents', in_pool: '/my/team', teamed: '/my/team', confirmed: '/my/team' };
// R1-G: per-stage deep-link targets (GUARDIAN context). pending_consent→the signing list, confirmed→the
// order to settle, active→the child's sessions (needs the row's student_id). submitted/in_pool/teamed/
// completed stay non-clickable — team formation is the STUDENT's act (no guardian team view exists) and
// completed has no next action.
const guardianDeep = (stage: string, row: Row): string | undefined =>
  stage === 'pending_consent' ? '/consents'
    : stage === 'confirmed' ? '/my/payments'
      : stage === 'active' ? `/family/sessions?student=${row.student_id}`
        : undefined;

export function Enrolments() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const navigate = useNavigate();
  const { has } = useIdentity();
  // The R1-S student predicate (verified student-only in R1-S). onStep deep-links are gated to students.
  const isStudent = isStudentActor(has);
  // R1-G: guardian is consent.sign-exclusive (capability_forbidden bars it from every capability group).
  const isGuardian = has('consent.sign');
  const { data, loading, error } = useResource<{ data: Row[] }>('/api/enrolments');
  const rows = data?.data ?? [];

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <SubPanel tone="neutral">
        <Title level={3}>{t('enrol.listTitle')}</Title>
        <Paragraph type="secondary">{t('enrol.listCaption')}</Paragraph>
        <DataBoundary loading={loading} error={error} empty={rows.length === 0}>
          <Table<Row>
            rowKey="id"
            dataSource={rows}
            pagination={false}
            columns={[
              { title: t('enrol.programme'), render: (_, row) => programmeName(row, locale) },
              { title: t('enrol.student'), render: (_, row) => personName(row.student_name) },
              {
                title: t('common.status'), dataIndex: 'status',
                // DS2: state as a VISUAL (StateBadge) + the SAME localized label (enrol.status.*) — same
                // three states as the old Tag colours (bad→warn · completed→ok · in-progress→action).
                render: (s: string) => (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <StateBadge state={TERMINAL_BAD.includes(s) ? 'warn' : s === 'completed' ? 'ok' : 'action'} title={t(`enrol.status.${s}`)} />
                    {t(`enrol.status.${s}`)}
                  </span>
                ),
              },
            ]}
            expandable={{
              expandedRowRender: (row) => TERMINAL_BAD.includes(row.status)
                ? (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <StateBadge state="warn" title={t(`enrol.status.${row.status}`)} />
                    {t(`enrol.status.${row.status}`)}
                  </span>
                )
                : (
                  <WizardRail
                    phases={[{
                      title: t('enrol.journeyTitle'),
                      steps: JOURNEY.map((s, i) => {
                        const cur = JOURNEY.indexOf(row.status);
                        const state = i < cur ? 'done' : i === cur ? 'current' : 'todo';
                        const key = isStudent ? DEEP[s] : isGuardian ? guardianDeep(s, row) : undefined;
                        return { label: t(`enrol.status.${s}`), state, key };
                      }),
                    }]}
                    // R1-S2 B1 + R1-G: onStep deep-links are VIEWER-scoped — student targets (…→/my/team) or
                    // guardian targets (confirmed→/my/payments, active→/family/sessions?student=). Display-only
                    // for teacher/ops. A stage with no target for the viewer stays non-clickable (WizardRail
                    // requires both onStep AND a per-step key).
                    onStep={isStudent || isGuardian ? (key) => navigate(key) : undefined}
                  />
                ),
            }}
          />
        </DataBoundary>
      </SubPanel>
    </Space>
  );
}

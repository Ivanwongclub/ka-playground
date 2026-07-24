// S02B step 1: the hub-and-spoke wizard (Spec Part D1). Sections save
// independently; readiness recomputes; pre-flight reports Error/Warning/Info;
// Publish stays disabled until 100% (D1). Config only — no personal data.
import { useCallback, useEffect, useState } from 'react';
import {
  Alert, App, Button, Card, Drawer, Flex, Input, InputNumber, List, Progress,
  Select, Switch, Table, Tag, Typography,
} from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';
import { kaColors } from '../theme/theme';

const { Title, Paragraph, Text } = Typography;

interface ProgrammeRow {
  id: number;
  code: string;
  name_en: string;
  name_tc: string;
  name_sc: string;
  status: string;
  jurisdiction: string;
  is_template: boolean;
}

interface Section {
  key: string;
  status: string;
  required: boolean;
  data: Record<string, unknown> | null;
}

interface WizardState {
  sections: Section[];
  readiness: { complete: number; required: number };
}

interface Finding {
  severity: 'error' | 'warning' | 'info';
  code: string;
  message: string;
}

const SECTION_KEY_MAP: Record<string, string> = {
  basics: 'basics', eligibility: 'eligibility', fees: 'fees', consent: 'consent',
  tracker: 'tracker', team_rules: 'teamRules', role_library: 'roleLibrary',
  learning: 'learning', certification: 'certification', integration: 'integration',
};

export function AdminProgrammes() {
  const { t, i18n } = useTranslation();
  const { message } = App.useApp();
  const [rows, setRows] = useState<ProgrammeRow[]>([]);
  const [selected, setSelected] = useState<ProgrammeRow | null>(null);
  const [wizard, setWizard] = useState<WizardState | null>(null);
  const [findings, setFindings] = useState<Finding[] | null>(null);
  const [openSection, setOpenSection] = useState<Section | null>(null);
  const [sectionDraft, setSectionDraft] = useState<Record<string, unknown>>({});

  const loadProgrammes = useCallback(async () => {
    const res = await authFetch('/api/admin/programmes');
    if (res.ok) setRows(((await res.json()) as { data: ProgrammeRow[] }).data);
  }, []);

  const loadWizard = useCallback(async (programme: ProgrammeRow) => {
    const res = await authFetch(`/api/admin/programmes/${programme.id}/wizard`);
    if (res.ok) setWizard((await res.json()) as WizardState);
  }, []);

  useEffect(() => {
    void loadProgrammes();
  }, [loadProgrammes]);

  const nameFor = (row: ProgrammeRow) =>
    i18n.language === 'zh-TC' ? row.name_tc : i18n.language === 'zh-SC' ? row.name_sc : row.name_en;

  const statusLabel: Record<string, string> = {
    not_started: t('wizard.statusNotStarted'),
    incomplete: t('wizard.statusIncomplete'),
    complete: t('wizard.statusComplete'),
    deferred: t('wizard.statusDeferred'),
  };

  const saveSection = async (status: 'complete' | 'incomplete') => {
    if (!selected || !openSection) return;
    const res = await authFetch(
      `/api/admin/programmes/${selected.id}/wizard/${openSection.key}`,
      {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status, data: sectionDraft }),
      },
    );
    if (res.status === 423) {
      message.error(t('wizard.publishDisabled'));
      return;
    }
    if (res.ok) {
      setOpenSection(null);
      await loadWizard(selected);
    }
  };

  const runPreFlight = async () => {
    if (!selected) return;
    const res = await authFetch(`/api/admin/programmes/${selected.id}/pre-flight`, { method: 'POST' });
    if (res.ok) setFindings(((await res.json()) as { findings: Finding[] }).findings);
  };

  const publish = async () => {
    if (!selected) return;
    const res = await authFetch(`/api/admin/programmes/${selected.id}/publish`, { method: 'POST' });
    if (res.ok) {
      void message.success(t('wizard.published'));
      setSelected({ ...selected, status: 'published' });
      await loadProgrammes();
    } else {
      await runPreFlight();
    }
  };

  const ready = wizard ? wizard.readiness.complete >= wizard.readiness.required : false;

  return (
    <div style={{ maxWidth: 1100 }}>
      <Title level={1}>{t('wizard.title')}</Title>
      <Paragraph type="secondary">{t('wizard.subtitle')}</Paragraph>

      <Table<ProgrammeRow>
        rowKey="id"
        dataSource={rows}
        pagination={false}
        onRow={(row) => ({
          onClick: () => {
            setSelected(row);
            setFindings(null);
            void loadWizard(row);
          },
        })}
        columns={[
          { title: t('wizard.code'), dataIndex: 'code', render: (v: string) => <Text code>{v}</Text> },
          { title: t('audit.colEntity'), key: 'name', render: (_, r) => nameFor(r) },
          {
            title: t('styleGuide.tableStatus'),
            dataIndex: 'status',
            render: (s: string) => (
              <Tag color={s === 'published' ? 'success' : 'default'}>
                {s === 'published' ? t('wizard.published') : t('wizard.draft')}
              </Tag>
            ),
          },
        ]}
      />

      {selected && wizard && (
        <Card style={{ marginTop: 24 }} title={<span>{nameFor(selected)} <Tag color={selected.status === 'published' ? 'success' : 'default'}>{selected.status === 'published' ? t('wizard.published') : t('wizard.draft')}</Tag></span>}>
          <Paragraph className="ka-tabular-nums">
            {t('wizard.readiness', wizard.readiness)}
          </Paragraph>
          <Progress
            percent={Math.round((wizard.readiness.complete / wizard.readiness.required) * 100)}
          />
          <List
            dataSource={wizard.sections}
            renderItem={(section, index) => (
              <List.Item
                actions={[
                  <Button
                    key="open"
                    type="text"
                    disabled={section.status === 'deferred'}
                    onClick={() => {
                      setOpenSection(section);
                      setSectionDraft(section.data ?? {});
                    }}
                  >
                    {t('styleGuide.tabTwo')}
                  </Button>,
                ]}
              >
                <List.Item.Meta
                  title={`${index + 1}. ${t(`wizard.sections.${SECTION_KEY_MAP[section.key]}`)}`}
                  description={
                    <Tag
                      color={
                        section.status === 'complete' ? 'success'
                          : section.status === 'incomplete' ? 'warning' : 'default'
                      }
                    >
                      {statusLabel[section.status]}
                    </Tag>
                  }
                />
              </List.Item>
            )}
          />
          <Flex gap={12} style={{ marginTop: 16 }}>
            <Button onClick={() => void runPreFlight()}>{t('wizard.preFlight')}</Button>
            <Button
              type="primary"
              className="ka-cta"
              disabled={!ready || selected.status !== 'draft'}
              title={!ready ? t('wizard.publishDisabled') : undefined}
              onClick={() => void publish()}
            >
              {t('wizard.publish')}
            </Button>
          </Flex>
          {findings && (
            <div style={{ marginTop: 16 }}>
              {findings.length === 0 && <Alert type="success" showIcon message={t('wizard.publish')} />}
              {findings.map((f) => (
                <Alert
                  key={f.code}
                  style={{ marginBottom: 8 }}
                  type={f.severity === 'error' ? 'error' : f.severity === 'warning' ? 'warning' : 'info'}
                  showIcon
                  message={`${t(`wizard.severity${f.severity[0].toUpperCase()}${f.severity.slice(1)}`)} — ${f.message}`}
                />
              ))}
            </div>
          )}
        </Card>
      )}

      <Drawer
        open={openSection !== null}
        onClose={() => setOpenSection(null)}
        width={480}
        title={openSection ? t(`wizard.sections.${SECTION_KEY_MAP[openSection.key]}`) : ''}
        styles={{ body: { background: kaColors.background } }}
      >
        {openSection && (
          <Flex vertical gap={16}>
            <SectionFields
              sectionKey={openSection.key}
              draft={sectionDraft}
              onChange={setSectionDraft}
            />
            <Flex gap={12}>
              <Button type="primary" onClick={() => void saveSection('complete')}>
                {t('wizard.markComplete')}
              </Button>
              <Button onClick={() => void saveSection('incomplete')}>
                {t('wizard.markIncomplete')}
              </Button>
            </Flex>
          </Flex>
        )}
      </Drawer>
    </div>
  );
}

/** Compact per-section fields — the payload shapes the pre-flight checks read. */
function SectionFields({
  sectionKey, draft, onChange,
}: {
  sectionKey: string;
  draft: Record<string, unknown>;
  onChange: (d: Record<string, unknown>) => void;
}) {
  const { t } = useTranslation();
  const set = (k: string, v: unknown) => onChange({ ...draft, [k]: v });

  switch (sectionKey) {
    case 'eligibility':
      return (
        <>
          <label className="ka-label">{t('wizard.sections.eligibility')}</label>
          <InputNumber
            addonBefore="min"
            value={(draft.min_enrolment as number) ?? undefined}
            onChange={(v) => set('min_enrolment', v)}
          />
        </>
      );
    case 'fees':
      return (
        <Flex gap={12} align="center">
          <Switch
            checked={Boolean(draft.has_fee_items)}
            onChange={(v) => set('has_fee_items', v)}
          />
          <Text>{t('wizard.sections.fees')}</Text>
        </Flex>
      );
    case 'consent':
      return (
        <>
          <label className="ka-label">{t('wizard.sections.consent')}</label>
          <Select
            style={{ width: '100%' }}
            value={(draft.template_ref as string) ?? undefined}
            onChange={(v) => set('template_ref', v)}
            options={[{ value: 'placeholder-s03', label: 'placeholder-s03' }]}
          />
        </>
      );
    case 'team_rules':
      return (
        <Flex gap={12}>
          <InputNumber addonBefore="min" value={(draft.min_size as number) ?? undefined} onChange={(v) => set('min_size', v)} />
          <InputNumber addonBefore="max" value={(draft.max_size as number) ?? undefined} onChange={(v) => set('max_size', v)} />
        </Flex>
      );
    case 'learning':
    case 'certification':
      return (
        <InputNumber
          addonBefore="%"
          min={0}
          max={100}
          value={(draft.attendance_threshold_pct as number) ?? undefined}
          onChange={(v) => set('attendance_threshold_pct', v)}
        />
      );
    default:
      return (
        <Input.TextArea
          rows={3}
          value={(draft.description as string) ?? ''}
          onChange={(e) => set('description', e.target.value)}
        />
      );
  }
}

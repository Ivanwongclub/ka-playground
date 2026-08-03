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
  marketing: 'marketing',
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
  const [loadError, setLoadError] = useState(false);

  const loadProgrammes = useCallback(async () => {
    try {
      const res = await authFetch('/api/admin/programmes');
      if (!res.ok) throw new Error(String(res.status));
      setRows(((await res.json()) as { data: ProgrammeRow[] }).data);
      setLoadError(false);
    } catch {
      setLoadError(true); // S-UX2a: surface a load failure instead of a silently-blank table
    }
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
    optional: t('wizard.statusOptional'),
  };
  // Marketing (S-MARKETPLACE-A) is OPTIONAL-but-available: with no saved row the backend returns
  // 'deferred' (the shared optional default), which would read as Phase-2 "Deferred" and disable its
  // editor. It is available now — surface it as "Optional" and keep its editor open. (Integration stays
  // genuinely deferred.)
  const displayStatus = (s: Section) => (s.key === 'marketing' && s.status === 'deferred' ? 'optional' : s.status);

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
    if (res.ok) {
      setOpenSection(null);
      await loadWizard(selected);
      return;
    }
    // Surface the server's message — the 423 lock, or a 422 validation error such as the marketing
    // section's `marketing.language_incomplete` (STEP 1 gate). The server is authoritative; never swallow.
    let msg = t('wizard.publishDisabled');
    try {
      const body = (await res.json()) as { message?: string; errors?: Record<string, string[]> };
      const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
      if (firstError ?? body.message) msg = (firstError ?? body.message) as string;
    } catch {
      /* no JSON body */
    }
    void message.error(msg);
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
      {loadError && <Alert type="error" showIcon message={t('data.error')} style={{ marginBottom: 16 }} />}

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
                    disabled={displayStatus(section) === 'deferred'}
                    onClick={() => {
                      setOpenSection(section);
                      // Seed the marketing brand-colour default so a never-touched picker (which already
                      // DISPLAYS a colour) counts toward completeness instead of a confusing "missing" reject.
                      setSectionDraft(section.data ?? (section.key === 'marketing' ? { brand_color: '#7A3B57' } : {}));
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
                        displayStatus(section) === 'complete' ? 'success'
                          : displayStatus(section) === 'incomplete' ? 'warning'
                            : displayStatus(section) === 'optional' ? 'processing' : 'default'
                      }
                    >
                      {statusLabel[displayStatus(section)]}
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
            addonBefore={t('field.min')}
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
            options={[{ value: 'placeholder-s03', label: t('consent.placeholderOption') }]}
          />
        </>
      );
    case 'team_rules':
      return (
        <Flex gap={12}>
          <InputNumber addonBefore={t('field.min')} value={(draft.min_size as number) ?? undefined} onChange={(v) => set('min_size', v)} />
          <InputNumber addonBefore={t('field.max')} value={(draft.max_size as number) ?? undefined} onChange={(v) => set('max_size', v)} />
        </Flex>
      );
    case 'learning':
    case 'certification':
      return (
        <InputNumber
          addonBefore={t('field.percent')}
          min={0}
          max={100}
          value={(draft.attendance_threshold_pct as number) ?? undefined}
          onChange={(v) => set('attendance_threshold_pct', v)}
        />
      );
    case 'marketing': {
      // S-MARKETPLACE-A STEP 3 (text-first — no imagery). Each field is trilingual (EN/繁/简); a
      // `complete` save with any language gap is rejected server-side (marketing.language_incomplete).
      const tri = (field: string) => (draft[field] as Record<string, string> | undefined) ?? {};
      const setLang = (field: string, lang: string, v: string) =>
        onChange({ ...draft, [field]: { ...tri(field), [lang]: v } });
      const fields: Array<[string, string]> = [
        ['tagline', t('marketing.tagline')], ['category', t('marketing.category')],
        ['age_range', t('marketing.ageRange')], ['duration', t('marketing.duration')],
      ];
      return (
        <>
          {fields.map(([f, label]) => (
            <div key={f}>
              <label className="ka-label">{label}</label>
              <Input placeholder="EN" value={tri(f).en ?? ''} onChange={(e) => setLang(f, 'en', e.target.value)} />
              <Input placeholder="繁體中文" value={tri(f).tc ?? ''} onChange={(e) => setLang(f, 'tc', e.target.value)} style={{ marginTop: 6 }} />
              <Input placeholder="简体中文" value={tri(f).sc ?? ''} onChange={(e) => setLang(f, 'sc', e.target.value)} style={{ marginTop: 6 }} />
            </div>
          ))}
          <div>
            <label className="ka-label">{t('marketing.brandColor')}</label>
            <Input type="color" value={(draft.brand_color as string) ?? '#7A3B57'} onChange={(e) => set('brand_color', e.target.value)} style={{ width: 96 }} />
          </div>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('marketing.imageryDeferred')}</Text>
        </>
      );
    }
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

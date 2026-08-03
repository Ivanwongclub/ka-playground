// S-UX3-8 STEP 2 — the Member surfaces (events · directory · profile), over the built S06 reads/writes.
// Reads + clean self-writes only; no elevation, no refusal to surface. Gated member_directory.view.
import { useEffect, useState } from 'react';
import { Alert, App, Button, Card, Empty, Input, List, Segmented, Space, Switch, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { personName } from '../display/names';
import { formatHkt } from '../display/date';

const { Title, Paragraph, Text } = Typography;

interface EventRow { id: string; title_en: string; title_tc: string; title_sc: string; starts_at: string; location: string | null; status: string }
interface RsvpRow { event_id: string; status: string }
interface DirRow { user_id: number; display_name: string; headline: string | null }
interface Profile { display_name: string | null; headline: string | null; visible: boolean }

function eventTitle(e: EventRow, locale: KaLocale): string {
  return (locale === 'zh-TC' ? e.title_tc : locale === 'zh-SC' ? e.title_sc : e.title_en) || e.title_en;
}

// ── Events + RSVP ─────────────────────────────────────────────────────────────────────────────────
export function MemberEvents() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { message } = App.useApp();
  const events = useResource<{ events: EventRow[] }>('/api/events');
  const rsvps = useResource<{ rsvps: RsvpRow[] }>('/api/my/rsvps');

  const mine = new Map((rsvps.data?.rsvps ?? []).map((r) => [r.event_id, r.status]));

  const setRsvp = async (eventId: string, status: string) => {
    const r = await mutate(`/api/events/${eventId}/rsvp`, { status });
    if (r.ok) { void message.success(t('community.rsvpSaved')); rsvps.reload(); }
    else void message.error(r.message ?? t('mutate.failed'));
  };

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('community.eventsTitle')}</Title>
        <Paragraph type="secondary">{t('community.eventsSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={events.loading || rsvps.loading} error={events.error} empty={(events.data?.events.length ?? 0) === 0}>
        <List<EventRow>
          dataSource={events.data?.events ?? []}
          renderItem={(e) => (
            <List.Item
              key={e.id}
              actions={[
                <Segmented
                  key="rsvp"
                  size="small"
                  value={mine.get(e.id) ?? ''}
                  onChange={(v) => void setRsvp(e.id, String(v))}
                  options={[
                    { value: 'going', label: t('community.rsvpGoing') },
                    { value: 'maybe', label: t('community.rsvpMaybe') },
                    { value: 'not_going', label: t('community.rsvpNotGoing') },
                  ]}
                />,
              ]}
            >
              <List.Item.Meta
                title={eventTitle(e, locale)}
                description={
                  <Text type="secondary">
                    {formatHkt(e.starts_at, locale)}{e.location ? ` · ${e.location}` : ''}
                  </Text>
                }
              />
            </List.Item>
          )}
        />
      </DataBoundary>
    </Space>
  );
}

// ── Directory ─────────────────────────────────────────────────────────────────────────────────────
export function MemberDirectory() {
  const { t } = useTranslation();
  const dir = useResource<{ directory: DirRow[] }>('/api/directory');

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('community.dirTitle')}</Title>
        <Paragraph type="secondary">{t('community.dirSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={dir.loading} error={dir.error} empty={(dir.data?.directory.length ?? 0) === 0}>
        <List<DirRow>
          grid={{ gutter: 12, xs: 1, sm: 2, md: 3 }}
          dataSource={dir.data?.directory ?? []}
          renderItem={(m) => (
            <List.Item key={m.user_id}>
              <Card size="small">
                <Text strong>{personName(m.display_name)}</Text>
                {m.headline && <Paragraph type="secondary" style={{ marginBottom: 0 }}>{m.headline}</Paragraph>}
              </Card>
            </List.Item>
          )}
        />
      </DataBoundary>
    </Space>
  );
}

// ── Profile editor (self) ───────────────────────────────────────────────────────────────────────────
export function MemberProfile() {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const profile = useResource<Profile>('/api/my/profile');
  const [displayName, setDisplayName] = useState('');
  const [headline, setHeadline] = useState('');
  const [visible, setVisible] = useState(true);

  // Pre-fill once the self-read lands (creatable empty state when the member has no profile yet).
  useEffect(() => {
    if (profile.data) {
      setDisplayName(profile.data.display_name ?? '');
      setHeadline(profile.data.headline ?? '');
      setVisible(profile.data.visible);
    }
  }, [profile.data]);

  // Academy-admin overlap: the nav reveals Profile on member_directory.view, but /my/profile is
  // role:member → a non-member gets 403. Handle GRACEFULLY (neutral state, not an error boundary).
  if (profile.error && profile.error.includes('403')) {
    return <Empty description={t('community.notAMember')} style={{ padding: 48 }} />;
  }

  const save = async () => {
    if (displayName.trim().length < 1) return;
    const r = await mutate('/api/my/profile', { display_name: displayName.trim(), headline: headline.trim() || null, visible });
    if (r.ok) { void message.success(t('community.saved')); profile.reload(); }
    else void message.error(r.message ?? (r.status === 403 ? t('mutate.forbidden') : t('mutate.failed')));
  };

  return (
    <Space direction="vertical" size="large" style={{ width: '100%', maxWidth: 520 }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('community.profileTitle')}</Title>
        <Paragraph type="secondary">{t('community.profileSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={profile.loading} error={profile.error && profile.error.includes('403') ? null : profile.error}>
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
          <div>
            <label className="ka-label">{t('community.displayName')}</label>
            <Input value={displayName} onChange={(e) => setDisplayName(e.target.value)} maxLength={80} />
          </div>
          <div>
            <label className="ka-label">{t('community.headline')}</label>
            <Input value={headline} onChange={(e) => setHeadline(e.target.value)} maxLength={140} />
          </div>
          <Space>
            <Switch checked={visible} onChange={setVisible} />
            <span>{t('community.visible')}</span>
          </Space>
          <Alert type="info" showIcon message={t('community.visibleHint')} />
          <div>
            <Button type="primary" className="ka-cta" disabled={!displayName.trim()} onClick={() => void save()}>
              {t('community.save')}
            </Button>
          </div>
        </Space>
      </DataBoundary>
    </Space>
  );
}

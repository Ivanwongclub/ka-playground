// S-UX3-4 STEP 2 — the sessions / attendance surfaces over the (STEP-1 gated) reads + the S06 writes.
// Reads are child-safety-gated server-side; this UI never widens them. The mark write's refusals
// (403 not-your-session · 409 session-state · 404 no-booked-seat) are SHOWN-NOT-HIDDEN: the controls
// stay visible and the server's refusal is rendered, never pre-hidden by the client.
import { useEffect, useState } from 'react';
import { App, Button, List, Segmented, Select, Space, Typography } from 'antd';
import { useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { mutate } from '../api/mutate';
import { personName } from '../display/names';
import { formatHkt, formatHktTime, formatHktDate } from '../display/date';
import { StatusTag } from '../display/status';
import { SubPanel, EmptyState } from '@/ds2'; // DS2 rollout C2 — container framing (List/Card→SubPanel). The attendance
// MARK handler (mark) and the book/cancel handler (act) + their payloads are BYTE-IDENTICAL (see the proofs).

const { Title, Paragraph, Text } = Typography;

interface SessionRow { id: string; title: string; starts_at: string; ends_at: string; status: string; team_id?: string | null; booking_status?: string | null }
interface MentorSessionRow extends SessionRow { capacity: number; booked: number; attended: number; no_show: number }
interface RosterEntry { student_id: number; student_name: string | null; status: string }
interface RosterPayload { session: { id: string; title: string; starts_at: string; ends_at: string; status: string; capacity: number }; roster: RosterEntry[] }
interface EnrolChildRow { student_id: number; student_name: string | null }
interface ProgrammeRow { id: number; code: string; name_en: string; name_tc: string; name_sc: string }

function programmeName(p: ProgrammeRow, locale: KaLocale): string {
  return (locale === 'zh-TC' ? p.name_tc : locale === 'zh-SC' ? p.name_sc : p.name_en) || p.name_en;
}

function whenLabel(s: SessionRow, locale: KaLocale): string {
  // S-FIX-UX-1 D5: show start AND end (end as time-only beside the full start).
  return `${formatHkt(s.starts_at, locale)} – ${formatHktTime(s.ends_at, locale)}`;
}

// ── A session's attendance chip (read views) ────────────────────────────────────────────────────────
function SessionMeta({ s, locale }: { s: SessionRow; locale: KaLocale }) {
  return (
    <Text type="secondary">
      {whenLabel(s, locale)} · <StatusTag domain="sessionStatus" value={s.status} />
    </Text>
  );
}

// R1-S2 B2: group a student's sessions by HKT calendar day. UPCOMING days first (ascending), PAST days
// after (most-recent first); within a day, by start time. Presentation only — no read/write change.
const hktDayKey = (iso: string): string =>
  new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Hong_Kong' }).format(new Date(iso.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')));
const startMs = (s: SessionRow): number => Date.parse(s.starts_at.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
function groupByDay(sessions: SessionRow[], todayKey: string): { key: string; sessions: SessionRow[] }[] {
  const map = new Map<string, SessionRow[]>();
  for (const s of sessions) {
    const k = hktDayKey(s.starts_at);
    const arr = map.get(k);
    if (arr) arr.push(s); else map.set(k, [s]);
  }
  for (const arr of map.values()) arr.sort((a, b) => startMs(a) - startMs(b));
  const keys = [...map.keys()];
  const upcoming = keys.filter((k) => k >= todayKey).sort();
  const past = keys.filter((k) => k < todayKey).sort().reverse();
  return [...upcoming, ...past].map((k) => ({ key: k, sessions: map.get(k) ?? [] }));
}

// ── Student: My Sessions (book / cancel own seat + own attendance) ──────────────────────────────────
export function MySessions() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { message } = App.useApp();
  const res = useResource<{ sessions: SessionRow[] }>('/api/my/sessions');

  const act = async (id: string, path: 'book' | 'cancel') => {
    const r = await mutate(`/api/my/sessions/${id}/${path}`);
    if (r.ok) { void message.success(t(path === 'book' ? 'attendance.booked' : 'attendance.cancelled')); res.reload(); }
    else void message.error(r.message ?? t('mutate.failed'));
  };

  const booked = (s: SessionRow) => s.booking_status === 'booked' || s.booking_status === 'waitlisted';
  const bookable = (s: SessionRow) => (s.booking_status == null || s.booking_status === 'cancelled') && (s.status === 'published' || s.status === 'full');

  // The session row — actions (book/cancel via act()) BYTE-IDENTICAL, extracted so each day-group reuses it.
  const renderSession = (s: SessionRow) => (
    <List.Item
      key={s.id}
      actions={[
        <StatusTag key="bk" domain="bookingStatus" value={s.booking_status} />,
        booked(s)
          ? <Button key="act" size="small" onClick={() => void act(s.id, 'cancel')}>{t('attendance.cancel')}</Button>
          : bookable(s)
            ? <Button key="act" size="small" type="primary" className="ka-cta" onClick={() => void act(s.id, 'book')}>{t('attendance.book')}</Button>
            : <span key="act" />,
      ]}
    >
      <List.Item.Meta title={s.title} description={<SessionMeta s={s} locale={locale} />} />
    </List.Item>
  );
  const todayKey = hktDayKey(new Date().toISOString());

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('attendance.myTitle')}</Title>
        <Paragraph type="secondary">{t('attendance.mySubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={res.loading} error={res.error} empty={(res.data?.sessions.length ?? 0) === 0}>
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
          {groupByDay(res.data?.sessions ?? [], todayKey).map((g) => (
            <SubPanel key={g.key} tone="neutral">
              <Text strong>{g.key === todayKey ? t('attendance.today') : formatHktDate(g.key, locale)}</Text>
              <List<SessionRow> dataSource={g.sessions} renderItem={renderSession} />
            </SubPanel>
          ))}
        </Space>
      </DataBoundary>
    </Space>
  );
}

// ── Guardian: My Child's Sessions (read-only; child picker from enrolments) ─────────────────────────
export function ChildSessions() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  // S-FIX-UX-1 D2: derive the picker from enrolments (not consent-requests) so a child with no
  // consent request still appears — distinct on (student_id, student_name).
  const children = useResource<{ data: EnrolChildRow[] }>('/api/enrolments');
  const [params] = useSearchParams();
  const [studentId, setStudentId] = useState<number | null>(null);

  // Distinct children the guardian has an enrolment for (each enrolment carries the student).
  const options = Array.from(
    new Map((children.data?.data ?? []).map((c) => [c.student_id, c.student_name])).entries(),
  ).map(([id, name]) => ({ value: id, label: personName(name) }));

  // R1-G: preselect the ?student= child (from My Children "view sessions") when it's a real option;
  // otherwise fall back to the first child — the prior default. Only until the user picks explicitly.
  useEffect(() => {
    if (studentId != null || options.length === 0) return;
    const requested = Number(params.get('student'));
    setStudentId(options.some((o) => o.value === requested) ? requested : options[0].value);
  }, [options, studentId, params]);

  const sessions = useResource<{ sessions: SessionRow[] }>(studentId != null ? `/api/my/students/${studentId}/sessions` : null);

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('attendance.childTitle')}</Title>
        <Paragraph type="secondary">{t('attendance.childSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={children.loading} error={children.error} empty={options.length === 0}>
        <Select
          style={{ minWidth: 240 }}
          value={studentId ?? undefined}
          options={options}
          onChange={(v) => setStudentId(v)}
          aria-label={t('attendance.childPick')}
        />
        <DataBoundary loading={sessions.loading} error={sessions.error} empty={(sessions.data?.sessions.length ?? 0) === 0}>
          <SubPanel tone="neutral">
          <List<SessionRow>
            dataSource={sessions.data?.sessions ?? []}
            renderItem={(s) => (
              <List.Item key={s.id} actions={[<StatusTag key="bk" domain="bookingStatus" value={s.booking_status} />]}>
                <List.Item.Meta title={s.title} description={<SessionMeta s={s} locale={locale} />} />
              </List.Item>
            )}
          />
          </SubPanel>
        </DataBoundary>
      </DataBoundary>
    </Space>
  );
}

// ── Shared roster + mark (mentor & ops) ─────────────────────────────────────────────────────────────
// The S06 mark write is BINARY: attended | no_show. There is no "late" status in the engine, so the
// control offers Present (attended) / Absent (no_show) only — faithful to what the server accepts.
function RosterMark({ sessionId }: { sessionId: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { message } = App.useApp();
  const res = useResource<RosterPayload>(`/api/admin/sessions/${sessionId}/roster`);

  const mark = async (studentId: number, status: string) => {
    const r = await mutate(`/api/admin/sessions/${sessionId}/attendance`, { student_id: studentId, status });
    if (r.ok) { void message.success(t('attendance.marked')); res.reload(); }
    // shown-not-hidden: render the server's refusal (409 wrong session-state, 403 not-your-session, 404 no-seat)
    else void message.error(r.message ?? t('mutate.failed'));
  };

  return (
    <DataBoundary loading={res.loading} error={res.error}>
      {res.data && (
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
          <Text type="secondary">
            {res.data.session.title} · {formatHkt(res.data.session.starts_at, locale)} – {formatHktTime(res.data.session.ends_at, locale)} · <StatusTag domain="sessionStatus" value={res.data.session.status} />
          </Text>
          <List<RosterEntry>
            dataSource={res.data.roster}
            locale={{ emptyText: <EmptyState size="inline" message={t('attendance.rosterEmpty')} /> }}
            renderItem={(m) => (
              <List.Item
                key={m.student_id}
                actions={[
                  <Segmented
                    key="mark"
                    size="small"
                    value={m.status === 'attended' || m.status === 'no_show' ? m.status : ''}
                    onChange={(v) => void mark(m.student_id, String(v))}
                    options={[
                      { value: 'attended', label: t('attendance.present') },
                      { value: 'no_show', label: t('attendance.absent') },
                    ]}
                  />,
                ]}
              >
                <List.Item.Meta
                  title={personName(m.student_name)}
                  description={<StatusTag domain="bookingStatus" value={m.status} />}
                />
              </List.Item>
            )}
          />
        </Space>
      )}
    </DataBoundary>
  );
}

// ── Teacher (mentor): Attendance — pick one of my sessions → its roster → mark ───────────────────────
export function MentorAttendance() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const res = useResource<{ sessions: MentorSessionRow[] }>('/api/my/mentor/sessions');
  const [sessionId, setSessionId] = useState<string | null>(null);

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('attendance.mentorTitle')}</Title>
        <Paragraph type="secondary">{t('attendance.mentorSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={res.loading} error={res.error} empty={(res.data?.sessions.length ?? 0) === 0}>
        <SubPanel tone="neutral">
        <List<MentorSessionRow>
          dataSource={res.data?.sessions ?? []}
          renderItem={(s) => (
            <List.Item
              key={s.id}
              actions={[
                <Text key="c" type="secondary">{t('attendance.attendedOf', { attended: s.attended, booked: s.booked })}</Text>,
                <Button key="open" size="small" type={sessionId === s.id ? 'primary' : 'default'} onClick={() => setSessionId(sessionId === s.id ? null : s.id)}>
                  {sessionId === s.id ? t('attendance.close') : t('attendance.take')}
                </Button>,
              ]}
            >
              <List.Item.Meta title={s.title} description={<SessionMeta s={s} locale={locale} />} />
            </List.Item>
          )}
        />
        </SubPanel>
        {sessionId && <SubPanel tone="neutral"><RosterMark sessionId={sessionId} /></SubPanel>}
      </DataBoundary>
    </Space>
  );
}

// ── Ops oversight: programme → attendance report → a session's roster + mark ─────────────────────────
// S-FIX-UX-1 D7: the programme picker reads /api/admin/attendance/programmes — an operations.manage-gated
// list of id/code/trilingual-names ONLY (no config data), so an operations-only admin has a source too.
export function OpsAttendance() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const programmes = useResource<{ data: ProgrammeRow[] }>('/api/admin/attendance/programmes');
  const [programmeId, setProgrammeId] = useState<number | null>(null);
  const [sessionId, setSessionId] = useState<string | null>(null);

  const report = useResource<{ sessions: (MentorSessionRow & { waitlisted: number })[] }>(
    programmeId != null ? `/api/admin/programmes/${programmeId}/attendance-report` : null,
  );

  const options = (programmes.data?.data ?? []).map((p) => ({ value: p.id, label: `${p.code} · ${programmeName(p, locale)}` }));

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <div>
        <Title level={3} style={{ marginBottom: 0 }}>{t('attendance.opsTitle')}</Title>
        <Paragraph type="secondary">{t('attendance.opsSubtitle')}</Paragraph>
      </div>
      <DataBoundary loading={programmes.loading} error={programmes.error} empty={options.length === 0}>
        <Select
          style={{ minWidth: 280 }}
          placeholder={t('attendance.pickProgramme')}
          value={programmeId ?? undefined}
          options={options}
          onChange={(v) => { setProgrammeId(v); setSessionId(null); }}
        />
        {programmeId != null && (
          <DataBoundary loading={report.loading} error={report.error} empty={(report.data?.sessions.length ?? 0) === 0}>
            <SubPanel tone="neutral">
            <List<MentorSessionRow & { waitlisted: number }>
              dataSource={report.data?.sessions ?? []}
              renderItem={(s) => (
                <List.Item
                  key={s.id}
                  actions={[
                    <Text key="c" type="secondary">{t('attendance.attendedOf', { attended: s.attended, booked: s.booked })}</Text>,
                    <Button key="open" size="small" type={sessionId === s.id ? 'primary' : 'default'} onClick={() => setSessionId(sessionId === s.id ? null : s.id)}>
                      {sessionId === s.id ? t('attendance.close') : t('attendance.open')}
                    </Button>,
                  ]}
                >
                  <List.Item.Meta title={s.title} description={<SessionMeta s={s} locale={locale} />} />
                </List.Item>
              )}
            />
            </SubPanel>
            {sessionId && <SubPanel tone="neutral"><RosterMark sessionId={sessionId} /></SubPanel>}
          </DataBoundary>
        )}
      </DataBoundary>
    </Space>
  );
}

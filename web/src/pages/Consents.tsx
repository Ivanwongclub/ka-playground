// S03: the guardian signing screen (FR036) — the first family-facing legal act.
// The three gates are SERVER-enforced; this screen mirrors them visibly:
// [1] scroll-to-end (server-recorded) → [2] affirmation → [3] signature capture.
// Every unmet gate shows an explicit locked state; the button is never the gate.
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  Alert, App as AntApp, Button, Checkbox, Descriptions, Input, Modal,
  Segmented, Space, Table, Tag, Typography,
} from 'antd';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { authFetch } from '../auth/session';
import { kaColors } from '../theme/theme';
import type { KaLocale } from '../i18n';
import { useResource, DataBoundary } from '../api/useResource';
import { programmeName, personName } from '../display/names';
import { formatHkt } from '../display/date';
import { StatusTag } from '../display/status';
import { SubPanel, UrgencyChip, urgencyLevel, urgencyDays, urgencyLabel, URGENCY } from '@/ds2';
// DS2 rollout C1 — markup-only framing (Card→SubPanel). The BI-6 signing flow (scroll-gate, affirmation,
// SignaturePad, sign()/decline() handlers, hashes) is BYTE-IDENTICAL. R0-B4 adds urgency to the LIST only.
import type { UrgencyLevel } from '@/ds2';

const { Title, Paragraph, Text } = Typography;

type Stroke = [number, number][];

interface RequestRow {
  id: string;
  programme_id: number;
  student_id: number;
  status: string;
  expires_at: string | null; // R0-B4: already returned by ConsentRequestController@index — was just untyped here
  programme_name_en: string | null;
  programme_name_tc: string | null;
  programme_name_sc: string | null;
  student_name: string | null;
}

interface DocumentPayload {
  body_html: string;
  language: string;
  template_sha256: string;
  rendered_sha256: string;
  version: number;
  is_placeholder: boolean;
}

interface SignedPayload {
  signature_id: string;
  language: string;
  template_sha256: string;
  rendered_sha256: string;
  signed_at: string;
}

const statusColor: Record<string, string> = {
  sent: 'gold', viewed: 'blue', signed: 'green',
  declined: 'red', superseded: 'default', voided: 'default', expired: 'default',
};

export function ConsentList() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  // S-UX2a: the shared fetch convention — res.ok guarded, errors caught (was an unguarded
  // r.json() that crashed the promise chain on any error response), loading/empty/error states.
  const { data, loading, error } = useResource<{ data: RequestRow[] }>('/api/consent-requests');
  const rows = data?.data ?? [];

  // Urgency applies ONLY to a live (awaiting-action) consent — a signed/declined/expired row has no
  // actionable deadline, so it carries no chip or emphasis. Not a proxy: the deadline is expires_at.
  const rowLevel = (row: RequestRow): UrgencyLevel =>
    ['sent', 'viewed'].includes(row.status) ? urgencyLevel(row.expires_at, URGENCY.consent) : 'none';

  return (
    <SubPanel tone="neutral">
      <Title level={3}>{t('consent.listTitle')}</Title>
      <Paragraph type="secondary">{t('consent.listCaption')}</Paragraph>
      <DataBoundary loading={loading} error={error} empty={rows.length === 0}>
        <Table<RequestRow>
          rowKey="id"
          dataSource={rows}
          pagination={false}
          rowClassName={(row) => { const lvl = rowLevel(row); return lvl !== 'none' ? `ds2-urgent--${lvl}` : ''; }}
          columns={[
            { title: t('consent.programme'), render: (_, row) => programmeName(row, locale) },
            { title: t('consent.student'), render: (_, row) => personName(row.student_name) },
            {
              title: t('consent.evidence.statusCol'), dataIndex: 'status',
              render: (s: string, row) => {
                const lvl = rowLevel(row);
                return (
                  <Space>
                    <Tag color={statusColor[s]}>{t(`consent.status.${s}`)}</Tag>
                    {lvl !== 'none' && <UrgencyChip level={lvl} label={urgencyLabel(lvl, urgencyDays(row.expires_at), t)} />}
                  </Space>
                );
              },
            },
            {
              title: t('common.actions'), dataIndex: 'id',
              render: (id: string, row) =>
                ['sent', 'viewed'].includes(row.status)
                  ? <Link to={`/consents/${id}`}>{t('consent.open')}</Link>
                  : null,
            },
          ]}
        />
      </DataBoundary>
    </SubPanel>
  );
}

function SignaturePad({ strokes, onChange }: { strokes: Stroke[]; onChange: (s: Stroke[], png: string) => void }) {
  const { t } = useTranslation();
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);

  const redraw = useCallback((all: Stroke[]) => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = kaColors.gold;
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    for (const stroke of all) {
      ctx.beginPath();
      stroke.forEach(([x, y], i) => (i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y)));
      ctx.stroke();
    }
  }, []);

  const point = (e: React.PointerEvent<HTMLCanvasElement>): [number, number] => {
    const rect = e.currentTarget.getBoundingClientRect();
    return [Math.round(e.clientX - rect.left), Math.round(e.clientY - rect.top)];
  };

  const emit = (all: Stroke[]) => {
    redraw(all);
    onChange(all, canvasRef.current?.toDataURL('image/png').split(',')[1] ?? '');
  };

  return (
    <Space direction="vertical" style={{ width: '100%' }}>
      <Text type="secondary">{t('consent.drawHere')}</Text>
      <canvas
        ref={canvasRef}
        width={440}
        height={160}
        style={{ background: kaColors.card, border: `1px dashed ${kaColors.border}`, borderRadius: 8, touchAction: 'none', maxWidth: '100%' }}
        onPointerDown={(e) => {
          drawing.current = true;
          e.currentTarget.setPointerCapture(e.pointerId);
          emit([...strokes, [point(e)]]);
        }}
        onPointerMove={(e) => {
          if (!drawing.current) return;
          const all = [...strokes];
          all[all.length - 1] = [...all[all.length - 1], point(e)];
          emit(all);
        }}
        onPointerUp={() => { drawing.current = false; }}
      />
      <Button size="small" onClick={() => emit([])}>{t('consent.clear')}</Button>
    </Space>
  );
}

export function ConsentSign() {
  const { t, i18n } = useTranslation();
  const { message } = AntApp.useApp();
  const { id } = useParams<{ id: string }>();
  const [language, setLanguage] = useState('en');
  const [doc, setDoc] = useState<DocumentPayload | null>(null);
  const [scrolled, setScrolled] = useState(false);
  const [affirmed, setAffirmed] = useState(false);
  const [method, setMethod] = useState<'drawn' | 'typed'>('drawn');
  const [strokes, setStrokes] = useState<Stroke[]>([]);
  const [png, setPng] = useState('');
  const [typedName, setTypedName] = useState('');
  const [signed, setSigned] = useState<SignedPayload | null>(null);
  const [declineOpen, setDeclineOpen] = useState(false);
  const [declineReason, setDeclineReason] = useState('');
  const [failed, setFailed] = useState(false);

  const load = useCallback((lang: string) => {
    // A language switch re-renders server-side and INVALIDATES the old scroll
    setScrolled(false);
    setAffirmed(false);
    void authFetch(`/api/consent-requests/${id}/document?language=${lang}`)
      .then(async (r) => (r.ok ? setDoc((await r.json()) as DocumentPayload) : setFailed(true)));
  }, [id]);

  useEffect(() => { load(language); }, [language, load]);

  const onScroll = (e: React.UIEvent<HTMLDivElement>) => {
    const el = e.currentTarget;
    if (!scrolled && el.scrollTop + el.clientHeight >= el.scrollHeight - 4) {
      void authFetch(`/api/consent-requests/${id}/scrolled`, { method: 'POST' })
        .then((r) => r.ok && setScrolled(true));
    }
  };

  const captured = method === 'drawn' ? strokes.length > 0 : typedName.trim() !== '';
  const canSign = scrolled && affirmed && captured;

  const sign = async () => {
    const response = await authFetch(`/api/consent-requests/${id}/sign`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        affirmed,
        method,
        ...(method === 'drawn' ? { strokes, image_base64: png } : { typed_name: typedName }),
      }),
    });
    if (response.ok) {
      setSigned((await response.json()) as SignedPayload);
    } else {
      message.error(((await response.json()) as { message?: string }).message ?? t('consent.loadError'));
    }
  };

  const decline = async () => {
    const response = await authFetch(`/api/consent-requests/${id}/decline`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reason: declineReason }),
    });
    if (response.ok) {
      setDeclineOpen(false);
      setSigned(null);
      setFailed(true);
    }
  };

  if (failed) {
    return <Alert type="info" showIcon message={t('consent.loadError')} />;
  }
  if (signed) {
    return (
      <SubPanel tone="neutral">
        <Title level={3}>{t('consent.signedTitle')}</Title>
        <Descriptions column={1} bordered size="small">
          <Descriptions.Item label={t('consent.signedLanguage')}><StatusTag domain="language" value={signed.language} /></Descriptions.Item>
          <Descriptions.Item label={t('consent.templateHash')}><Text code>{signed.template_sha256}</Text></Descriptions.Item>
          <Descriptions.Item label={t('consent.renderedHash')}><Text code>{signed.rendered_sha256}</Text></Descriptions.Item>
          <Descriptions.Item label={t('consent.signedAt')}>{formatHkt(signed.signed_at, i18n.language)}</Descriptions.Item>
        </Descriptions>
      </SubPanel>
    );
  }

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <SubPanel tone="neutral">
        <Title level={3}>{t('consent.title')}</Title>
        {doc?.is_placeholder && (
          <Alert type="warning" showIcon style={{ marginBottom: 16 }} message={t('consent.placeholderBanner')} />
        )}
        <Space style={{ marginBottom: 12 }}>
          <Text type="secondary">{t('consent.languageLabel')}</Text>
          <Segmented
            value={language}
            onChange={(v) => setLanguage(v as string)}
            options={[
              { value: 'en', label: 'EN' },
              { value: 'zh-TC', label: '繁體中文' },
              { value: 'zh-SC', label: '简体中文' },
            ]}
          />
        </Space>
        <div
          onScroll={onScroll}
          style={{ height: 260, overflowY: 'auto', padding: 16, background: kaColors.card, border: `1px solid ${kaColors.border}`, borderRadius: 8 }}
          // Server-rendered consent document (merge values are server-escaped)
          dangerouslySetInnerHTML={{ __html: doc?.body_html ?? '' }}
        />
        {scrolled
          ? <Alert type="success" showIcon style={{ marginTop: 12 }} message={t('consent.readDone')} />
          : <Alert type="warning" showIcon style={{ marginTop: 12 }} message={t('consent.readToEnd')} />}
        <Button danger style={{ marginTop: 12 }} onClick={() => setDeclineOpen(true)}>
          {t('consent.declineButton')}
        </Button>
      </SubPanel>

      {/* GATE 1 — locked until the server has recorded scroll-to-end */}
      {!scrolled ? (
        <SubPanel tone="neutral">
          <Alert type="error" showIcon message={t('consent.gate1Locked')} />
        </SubPanel>
      ) : (
        <SubPanel tone="neutral">
          {/* GATE 2 — affirmation */}
          <Checkbox checked={affirmed} onChange={(e) => setAffirmed(e.target.checked)}>
            {t('consent.affirmLabel')}
          </Checkbox>
          {!affirmed && <Alert type="error" showIcon style={{ marginTop: 12 }} message={t('consent.gate2Hint')} />}

          {/* GATE 3 — capture */}
          <div style={{ marginTop: 16, opacity: affirmed ? 1 : 0.45, pointerEvents: affirmed ? 'auto' : 'none' }}>
            <Segmented
              value={method}
              onChange={(v) => setMethod(v as 'drawn' | 'typed')}
              options={[
                { value: 'drawn', label: t('consent.signMethodDrawn') },
                { value: 'typed', label: t('consent.signMethodTyped') },
              ]}
              style={{ marginBottom: 12 }}
            />
            {method === 'drawn'
              ? <SignaturePad strokes={strokes} onChange={(s, p) => { setStrokes(s); setPng(p); }} />
              : <Input placeholder={t('consent.typeName')} value={typedName} onChange={(e) => setTypedName(e.target.value)} style={{ maxWidth: 320 }} />}
            {affirmed && !captured && <Alert type="error" showIcon style={{ marginTop: 12 }} message={t('consent.gate3Hint')} />}
          </div>

          <Space style={{ marginTop: 20 }}>
            <Button type="primary" disabled={!canSign} onClick={() => void sign()}>
              {t('consent.signButton')}
            </Button>
          </Space>
        </SubPanel>
      )}

      <Modal
        open={declineOpen}
        title={t('consent.declineConfirm')}
        okButtonProps={{ danger: true, disabled: declineReason.trim().length < 5 }}
        onOk={() => void decline()}
        onCancel={() => setDeclineOpen(false)}
      >
        <Input.TextArea
          rows={3}
          placeholder={t('consent.declineReason')}
          value={declineReason}
          onChange={(e) => setDeclineReason(e.target.value)}
        />
      </Modal>
    </Space>
  );
}

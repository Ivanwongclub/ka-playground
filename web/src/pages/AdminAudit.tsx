// S00 audit element: Admin › Audit — the append-only log, filterable by
// actor / entity / action / date (BI-1, GR003). Read-only by construction.
import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, DatePicker, Flex, Input, Table, Tag, Typography } from 'antd';
import type { Dayjs } from 'dayjs';
import { useTranslation } from 'react-i18next';

const { Title, Paragraph, Text } = Typography;

interface AuditRow {
  event_id: string;
  occurred_at: string;
  actor_id: number | null;
  entity_type: string;
  entity_id: string;
  action: string;
  from_state: string | null;
  to_state: string | null;
  reason: string | null;
}

interface Filters {
  actor_id?: string;
  entity_type?: string;
  action?: string;
  range?: [Dayjs | null, Dayjs | null] | null;
}

function formatHkt(iso: string, locale: string): string {
  const formatted = new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : locale, {
    timeZone: 'Asia/Hong_Kong',
    day: 'numeric',
    month: locale === 'en' ? 'short' : 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(iso));
  return formatted;
}

export function AdminAudit() {
  const { t, i18n } = useTranslation();
  const [rows, setRows] = useState<AuditRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(false);
  const [filters, setFilters] = useState<Filters>({});

  const load = useCallback(async (f: Filters, p: number) => {
    setLoading(true);
    setError(false);
    const params = new URLSearchParams({ page: String(p) });
    if (f.actor_id) params.set('actor_id', f.actor_id);
    if (f.entity_type) params.set('entity_type', f.entity_type);
    if (f.action) params.set('action', f.action);
    if (f.range?.[0]) params.set('from', f.range[0].toISOString());
    if (f.range?.[1]) params.set('to', f.range[1].toISOString());
    try {
      const res = await fetch(`/api/audit-events?${params.toString()}`);
      if (!res.ok) throw new Error(String(res.status));
      const body = (await res.json()) as { data: AuditRow[]; total: number };
      setRows(body.data);
      setTotal(body.total);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load(filters, page);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page]);

  return (
    <div style={{ maxWidth: 1100 }}>
      <Title level={1}>{t('audit.title')}</Title>
      <Paragraph type="secondary">{t('audit.subtitle')}</Paragraph>

      <Flex wrap gap={12} style={{ marginBottom: 16 }} align="end">
        <div>
          <label className="ka-label" htmlFor="f-actor">{t('audit.filterActor')}</label>
          <Input
            id="f-actor"
            style={{ width: 120 }}
            value={filters.actor_id}
            onChange={(e) => setFilters((f) => ({ ...f, actor_id: e.target.value }))}
          />
        </div>
        <div>
          <label className="ka-label" htmlFor="f-entity">{t('audit.filterEntityType')}</label>
          <Input
            id="f-entity"
            style={{ width: 160 }}
            value={filters.entity_type}
            onChange={(e) => setFilters((f) => ({ ...f, entity_type: e.target.value }))}
          />
        </div>
        <div>
          <label className="ka-label" htmlFor="f-action">{t('audit.filterAction')}</label>
          <Input
            id="f-action"
            style={{ width: 180 }}
            value={filters.action}
            onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value }))}
          />
        </div>
        <div>
          <label className="ka-label">{t('audit.filterDates')}</label>
          <DatePicker.RangePicker
            value={filters.range ?? null}
            onChange={(range) => setFilters((f) => ({ ...f, range }))}
          />
        </div>
        <Button type="primary" onClick={() => { setPage(1); void load(filters, 1); }}>
          {t('audit.apply')}
        </Button>
        <Button onClick={() => { setFilters({}); setPage(1); void load({}, 1); }}>
          {t('audit.reset')}
        </Button>
      </Flex>

      {error && <Alert type="error" showIcon message={t('audit.loadError')} style={{ marginBottom: 16 }} />}

      <Table<AuditRow>
        rowKey="event_id"
        loading={loading}
        dataSource={rows}
        pagination={{ current: page, total, pageSize: 20, onChange: setPage, showSizeChanger: false }}
        columns={[
          {
            title: t('audit.colTime'),
            dataIndex: 'occurred_at',
            width: 190,
            render: (v: string) => <span className="ka-tabular-nums">{formatHkt(v, i18n.language)}</span>,
          },
          {
            title: t('audit.colActor'),
            dataIndex: 'actor_id',
            width: 90,
            render: (v: number | null) => v ?? <Text type="secondary">{t('audit.system')}</Text>,
          },
          {
            title: t('audit.colEntity'),
            key: 'entity',
            render: (_, r) => (
              <span>
                <Tag>{r.entity_type}</Tag>
                <Text code>{r.entity_id}</Text>
              </span>
            ),
          },
          { title: t('audit.colAction'), dataIndex: 'action' },
          {
            title: t('audit.colStates'),
            key: 'states',
            render: (_, r) =>
              r.from_state || r.to_state ? (
                <span className="ka-tabular-nums">{r.from_state ?? '—'} → {r.to_state ?? '—'}</span>
              ) : (
                '—'
              ),
          },
          {
            title: t('audit.colReason'),
            dataIndex: 'reason',
            render: (v: string | null) => v ?? '—',
          },
        ]}
      />
    </div>
  );
}

// DS2 v3 interaction primitives (P0-3b). Pure/presentational and DATA-AGNOSTIC — they take props and never
// fetch. Invisible until a surface adopts one (barrel + import-guard gate adoption). i18n-native: all text
// arrives already-translated. BottomSheet (§3.11) is re-exported from the barrel (components/mobile), not here.
//   Board              — §3.8 read-only occupancy; NO drag API exists (dragging is un-callable by construction)
//   ActionRequiredList — §3.5 warning bar + count + rows (deadline loudest, whole-row nav)
//   OverviewTabs       — §3.6 antd Tabs; "All" summary (ZebraTable) default + per-item tabs
//   ElasticSearch      — §3.12 transparent typeahead grouped by kind; renders ONLY the results it is given
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Avatar, Tabs } from 'antd';
import { ChevronRight } from 'lucide-react';
import { ZebraTable } from './structure';
import type { Ds2Column } from './structure';
import { EmptyState } from './surfaces';
import type { UrgencyLevel } from '../display/urgency';
import './interactions.css';

// ── Board (§3.8) — read-only occupancy. There is NO drag handler prop anywhere: dragging is un-callable. ──
export interface BoardItem {
  id: string;
  label: ReactNode;
  monogram?: string;                              // people → monogram Avatar
  members?: { filled: number; total: number };    // teams → 5-dot member meter (capped at 5, success-filled)
  status?: ReactNode;                             // attention pill — the caller passes a <StatusTag/> (≤1)
  onClick?: () => void;                           // opens the record — the ONLY interaction (never drag)
}
export interface BoardColumn { title: ReactNode; count?: number; items: BoardItem[] }

/** A 5-dot member meter (kit §3.8): `filled` of `total`, capped at 5 dots, success-filled. Inline (not an atom). */
function MemberMeter({ filled, total }: { filled: number; total: number }) {
  const dots = Math.min(5, Math.max(0, total));
  const on = Math.min(dots, Math.max(0, filled));
  return (
    <span className="ds2-board__meter" role="img" aria-label={`${filled}/${total}`}>
      {Array.from({ length: dots }, (_, i) => (
        <span key={i} className={`ds2-board__dot${i < on ? ' ds2-board__dot--on' : ''}`} aria-hidden />
      ))}
    </span>
  );
}

/** Columns of item cards. Read-only: an item can only be opened (onClick), never dragged/reordered — no such
 *  prop exists. Hover-lift is presentational. Count chip per column. */
export function Board({ columns }: { columns: BoardColumn[] }) {
  return (
    <div className="ds2-board">
      {columns.map((col, ci) => (
        <div key={ci} className="ds2-board__col">
          <div className="ds2-board__colhead">
            <span className="ds2-board__coltitle">{col.title}</span>
            {col.count != null && <span className="ds2-board__count">{col.count}</span>}
          </div>
          <div className="ds2-board__items">
            {col.items.map((it) => {
              const clickable = it.onClick !== undefined;
              return (
                <div
                  key={it.id}
                  className={`ds2-board__item${clickable ? ' ds2-board__item--clickable' : ''}`}
                  role={clickable ? 'button' : undefined}
                  tabIndex={clickable ? 0 : undefined}
                  onClick={it.onClick}
                  onKeyDown={clickable ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); it.onClick!(); } } : undefined}
                >
                  {it.monogram != null && <Avatar size={32} className="ds2-board__avatar">{it.monogram}</Avatar>}
                  <div className="ds2-board__body">
                    <span className="ds2-board__label">{it.label}</span>
                    {it.members && <MemberMeter filled={it.members.filled} total={it.members.total} />}
                  </div>
                  {it.status}
                </div>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}

// ── ActionRequiredList (§3.5) — warning bar + count heading + deadline-loudest rows ───────────────────
export interface ActionItem {
  id: string;
  icon: ReactNode;                 // lucide glyph (44px tile)
  title: ReactNode;
  who?: ReactNode;                 // who-line
  deadlineLevel: UrgencyLevel;     // colors the loudest element
  deadlineLabel: ReactNode;        // the loud countdown ("Due in 2 days")
  deadlineSubLabel?: ReactNode;    // small-caps sub-label
  onClick: () => void;             // whole-row nav — ONE target
}

/** Guardian-home lead unit: a warning accent bar, a count heading, and rows where the DEADLINE is the loudest
 *  element (not the title). The whole row is one nav target. Icons are lucide ReactNodes (never emoji). */
export function ActionRequiredList({ heading, count, items }: { heading: ReactNode; count?: number; items: ActionItem[] }) {
  return (
    <section className="ds2-arl">
      <div className="ds2-arl__head">
        <span className="ds2-arl__heading">{heading}</span>
        {count != null && <span className="ds2-arl__count">{count}</span>}
      </div>
      <div className="ds2-arl__rows">
        {items.map((it) => (
          <div
            key={it.id}
            className="ds2-arl__row"
            role="button"
            tabIndex={0}
            onClick={it.onClick}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); it.onClick(); } }}
          >
            <span className="ds2-arl__tile" aria-hidden>{it.icon}</span>
            <div className="ds2-arl__main">
              <span className="ds2-arl__title">{it.title}</span>
              {it.who != null && <span className="ds2-arl__who">{it.who}</span>}
            </div>
            <div className={`ds2-arl__deadline ds2-arl__deadline--${it.deadlineLevel}`}>
              <span className="ds2-arl__deadline-lbl">{it.deadlineLabel}</span>
              {it.deadlineSubLabel != null && <span className="ds2-arl__deadline-sub">{it.deadlineSubLabel}</span>}
            </div>
            <ChevronRight size={20} strokeWidth={1.9} className="ds2-arl__chev" aria-hidden />
          </div>
        ))}
      </div>
    </section>
  );
}

// ── OverviewTabs (§3.6) — overview-first: an "All" summary table + per-item tabs ──────────────────────
export interface OverviewCol { key: string; title: ReactNode }
export interface OverviewRow {
  key: string;
  name: ReactNode;
  onOpen: () => void;                  // row-click drills in (via the name cell)
  counts: Record<string, ReactNode>;   // funnel counts = PLAIN values (not pills)
  window?: ReactNode;
  attention?: ReactNode;               // ≤1 attention pill (caller passes <StatusTag/>)
}
export interface OverviewItemTab { key: string; label: ReactNode; children: ReactNode }

/** antd Tabs (underline). The FIRST/default tab is the "All" overview — a ZebraTable with one row per item:
 *  the name drills in (clickable cell), funnel counts are plain values, and there is ONE attention slot/row.
 *  Per-item tabs follow. Overview-first is enforced by "All" being the default active key. */
export function OverviewTabs({ allLabel, columns, rows, tabs = [] }: {
  allLabel: ReactNode;
  columns: OverviewCol[];
  rows: OverviewRow[];
  tabs?: OverviewItemTab[];
}) {
  const zebraCols: Ds2Column<OverviewRow>[] = [
    { key: 'name', title: allLabel, type: 'text', render: (r) => (
      <button type="button" className="ds2-ovt__name" onClick={r.onOpen}>{r.name}</button>
    ) },
    ...columns.map((c): Ds2Column<OverviewRow> => ({
      key: c.key, title: c.title, type: 'text', render: (r) => <span className="ds2-ovt__count">{r.counts[c.key] ?? '—'}</span>,
    })),
    { key: '__window', title: '', type: 'text', render: (r) => r.window ?? null },
    { key: '__attn', title: '', type: 'status', render: (r) => r.attention ?? null },
  ];
  const items = [
    { key: '__all', label: allLabel, children: <ZebraTable<OverviewRow> columns={zebraCols} data={rows} rowKey={(r) => r.key} /> },
    ...tabs.map((tb) => ({ key: tb.key, label: tb.label, children: tb.children })),
  ];
  return <Tabs className="ds2-ovt" defaultActiveKey="__all" items={items} />;
}

// ── ElasticSearch (§3.12) — transparent typeahead; renders ONLY the results it is handed ──────────────
export interface SearchResult { id: string; label: ReactNode; meta?: ReactNode; onOpen: () => void }
export interface SearchGroup { kind: ReactNode; results: SearchResult[] }

/** Controlled input (transparent, no fill) + a typeahead dropdown grouped by record kind. This primitive does
 *  NOT fetch and does NOT filter — the caller runs the entitlement-scoped search (onQuery) and hands back only
 *  entitled `groups` (entitlement-iff). Enter opens the top hit. Empty (with a query) shows the EmptyState. */
export function ElasticSearch({ value, onQuery, groups, placeholder, emptyMessage, onEnter, loading = false }: {
  value: string;
  onQuery: (q: string) => void;
  groups: SearchGroup[];
  placeholder?: string;
  emptyMessage: ReactNode;
  onEnter?: () => void;
  loading?: boolean;
}) {
  const [focused, setFocused] = useState(false);
  const total = groups.reduce((n, g) => n + g.results.length, 0);
  const topHit = groups.find((g) => g.results.length > 0)?.results[0];
  const open = focused && value.trim().length > 0;

  return (
    <div className="ds2-search">
      <input
        className="ds2-search__input"
        type="search"
        value={value}
        placeholder={placeholder}
        onChange={(e) => onQuery(e.target.value)}
        onFocus={() => setFocused(true)}
        onBlur={() => window.setTimeout(() => setFocused(false), 150)}
        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); (onEnter ?? topHit?.onOpen)?.(); } }}
        role="combobox"
        aria-expanded={open}
      />
      {open && (
        <div className="ds2-search__panel" role="listbox">
          {total === 0 && !loading
            ? <EmptyState message={emptyMessage} size="inline" />
            : groups.filter((g) => g.results.length > 0).map((g, gi) => (
                <div key={gi} className="ds2-search__group">
                  <div className="ds2-search__kind">{g.kind}</div>
                  {g.results.map((r) => (
                    <button key={r.id} type="button" className="ds2-search__result" role="option" onClick={r.onOpen}>
                      <span className="ds2-search__result-label">{r.label}</span>
                      {r.meta != null && <span className="ds2-search__result-meta">{r.meta}</span>}
                    </button>
                  ))}
                </div>
              ))}
        </div>
      )}
    </div>
  );
}

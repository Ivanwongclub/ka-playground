// DS2 structure primitives (STEP 3). Each enforces its rule by API SHAPE:
//   SubPanel / ZoneStack — D6 zones-not-cages: shade-banded panels + a gap; NO border/divider prop exists.
//   Attest               — D5 THE honesty component (read hardest): takes the fact (status + dated) and a
//                          record LINK (onViewRecord), never a reassurance-sentence prop. Its type makes an
//                          attested state REQUIRE onViewRecord, so an evidential fact can be neither
//                          narrated (no prose slot) nor buried (the record is always reachable).
//   ZebraTable           — D6 horizontal: zebra row-banding + alignment BY COLUMN TYPE (money right, text
//                          left, status/action defined zones); NO per-column border option.
//   WizardRail           — grouped stepper: per-phase counts + state icons; steps take a `state` ENUM, not
//                          a caption — prose captions are un-passable.
//   FormLanguageSwitcher — the ONLY trilingual primitive: a form-level switch + per-language completeness
//                          dots. There is no per-field trilingual component to import, so per-field EN/繁/简
//                          triplets are un-buildable.
// i18n-native: all text arrives as already-translated ReactNode. On the --ka-* tokens; wraps antd where it
// has the primitive (Table, Segmented).
import type { ReactNode } from 'react';
import { Table, Segmented } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { Link } from 'react-router-dom';
import { StatusAtom, DatedBadge, MetaChip, Seal, StatChip } from './atoms';
import './structure.css';

export type Tone = 'neutral' | 'attested' | 'action';

/** A shade-banded zone (D6). Tone selects the shade; there is no border/heavy-line prop. */
export function SubPanel({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return <div className={`ds2-subpanel ds2-subpanel--${tone}`}>{children}</div>;
}

/** A vertical stack of sections (D6). Separation is the gap + the SubPanel shades — no divider prop. */
export function ZoneStack({ children }: { children: ReactNode }) {
  return <div className="ds2-zonestack">{children}</div>;
}

/**
 * StatCard — the KPI tile extracted from D1/D2 (Dashboard, Access & Identity, Consent
 * Evidence hand-rolled it 3×; rule of three). A SubPanel zone + a MetaChip header
 * (optional icon + label) + a token KPI number (display font, tabular-nums). `alert`
 * flips the zone to the `action` tone with a danger number; `accent` colours the
 * number when not alert. Forward-compat for the money tier (M1–M4): optional `seal`
 * (a Seal in the header, the "confirmed" motif) and `sub` (a sub-line, e.g. a StatChip
 * count), plus accent `'warn'` for money "awaiting". `value` is a ReactNode, so a
 * formatMoney string drops in.
 *
 * DS2 v2 (R0-B1 · DS2-V2-SPEC §2.5/A2) — ADDITIVE, all optional, default path byte-identical:
 *   · count + unit → render the StatChip sub-line internally (absorbs Payments' local variant);
 *     they take precedence over `sub` when both are given.
 *   · to XOR onClick → drill-down is BUILT IN (absorbs the .ka-dash-kpi wrapper): `to` renders a
 *     react-router Link (semantic <a>, native keyboard/focus); `onClick` renders a role=button with
 *     Enter/Space activation and a focus-visible outline. With NEITHER, the card is a plain (non-
 *     interactive) figure and renders exactly as v1.
 */
export function StatCard({ label, value, icon, accent = 'default', alert = false, seal = false, sub, count, unit, to, onClick }: {
  label: ReactNode;
  value: ReactNode;
  icon?: ReactNode;
  accent?: 'default' | 'gold' | 'warn';
  alert?: boolean;
  seal?: boolean;
  sub?: ReactNode;
  count?: number;
  unit?: ReactNode;
  to?: string;
  onClick?: () => void;
}) {
  const numClass = alert
    ? ' ds2-statcard__value--danger'
    : accent === 'gold'
      ? ' ds2-statcard__value--gold'
      : accent === 'warn'
        ? ' ds2-statcard__value--warn'
        : '';
  // count+unit render the StatChip sub-line; they win over `sub` when both are present (spec §2.5).
  const subline = count != null && unit != null ? <StatChip value={count} label={unit} /> : sub;
  const body = (
    <SubPanel tone={alert ? 'action' : 'neutral'}>
      <MetaChip icon={icon}>{seal ? <><Seal size={15} /> {label}</> : label}</MetaChip>
      <div className={`ds2-statcard__value${numClass}`}>{value}</div>
      {subline != null && <div className="ds2-statcard__sub">{subline}</div>}
    </SubPanel>
  );
  // Default path — no to/onClick → byte-identical to v1 (the body, unwrapped).
  if (to === undefined && onClick === undefined) return body;
  if (to !== undefined) return <Link className="ds2-statcard-link" to={to}>{body}</Link>;
  return (
    <div
      className="ds2-statcard-btn"
      role="button"
      tabIndex={0}
      onClick={onClick}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick?.(); } }}
    >
      {body}
    </div>
  );
}

// ── Attest — the honesty core ───────────────────────────────────────────────────────────────────────
type AttestCommon = {
  status: ReactNode; // the loud status atom (short) — NOT a paragraph slot
  icon?: ReactNode;
  dated?: { label: ReactNode; date: string; locale: string }; // the evidential fact as a DATE, not a sentence
  action?: ReactNode;
};
// An ATTESTED state MUST supply onViewRecord — the full audited record is always reachable (never buried).
type AttestAttested = AttestCommon & { tone: 'attested'; onViewRecord: () => void; viewRecordLabel: ReactNode };
// An ACTION state (pending) has no record yet, and cannot smuggle one.
type AttestAction = AttestCommon & { tone: 'action'; onViewRecord?: never; viewRecordLabel?: never };

/** D5: the fact as an atom, the record on demand. No prose/description prop anywhere. */
export function Attest(props: AttestAttested | AttestAction) {
  const { status, icon, dated, action, tone } = props;
  return (
    <SubPanel tone={tone}>
      <div className="ds2-attest">
        <StatusAtom icon={icon} status={status} tone={tone} />
        <span className="ds2-attest__spacer" />
        {dated && <DatedBadge label={dated.label} date={dated.date} locale={dated.locale} />}
        {props.tone === 'attested' && (
          <button type="button" className="ds2-attest__link" onClick={props.onViewRecord}>
            {props.viewRecordLabel}
          </button>
        )}
        {action}
      </div>
    </SubPanel>
  );
}

// ── ZebraTable — D6 horizontal ──────────────────────────────────────────────────────────────────────
export type Ds2ColType = 'text' | 'money' | 'status' | 'action';
export interface Ds2Column<T> {
  key: string;
  title: ReactNode;
  type: Ds2ColType;
  render: (row: T) => ReactNode;
  width?: number;
}
const ALIGN: Record<Ds2ColType, 'left' | 'right'> = { text: 'left', money: 'right', status: 'left', action: 'right' };
const ZONE_MINW: Partial<Record<Ds2ColType, number>> = { status: 150 };

/** Zebra row-banding + alignment discipline BY COLUMN TYPE. No per-column border option. Wraps antd Table
 *  (keeps sort/pagination/sticky). Money is forced right + display-weight; you cannot misalign it. */
export function ZebraTable<T extends object>({
  columns,
  data,
  rowKey,
  renderDetail,
}: {
  columns: Ds2Column<T>[];
  data: T[];
  rowKey: (row: T) => string;
  // R1-F1: optional expandable-row detail (evidence beside the button). Omitted → byte-identical to before.
  renderDetail?: (row: T) => ReactNode;
}) {
  const cols: ColumnsType<T> = columns.map((c) => ({
    key: c.key,
    title: c.title,
    align: ALIGN[c.type],
    width: c.width ?? ZONE_MINW[c.type],
    render: (_: unknown, row: T) =>
      c.type === 'money' ? <span className="ds2-money">{c.render(row)}</span> : c.render(row),
  }));
  return (
    <Table<T>
      className="ds2-zebra"
      columns={cols}
      dataSource={data}
      rowKey={rowKey}
      size="small"
      pagination={false}
      expandable={renderDetail ? { expandedRowRender: renderDetail } : undefined}
    />
  );
}

// ── WizardRail — grouped stepper ────────────────────────────────────────────────────────────────────
export type StepState = 'done' | 'current' | 'wip' | 'blocked' | 'todo' | 'deferred' | 'optional';
// S-TRACKER-1: `selected` is CLIENT SELECTION only — which step the reader is looking at. It is NOT a
// step state (the union above is the server's truth) and it never changes what `state` renders.
export interface RailStep { label: ReactNode; state: StepState; locked?: boolean; num?: number; key?: string; selected?: boolean }
export interface RailPhase { title: ReactNode; steps: RailStep[] }

const RAIL_CHECK = (
  <svg viewBox="0 0 24 24" style={{ width: 12, height: 12, stroke: '#241a12', strokeWidth: 3, fill: 'none', strokeLinecap: 'round', strokeLinejoin: 'round' }}>
    <path d="M20 6L9 17l-5-5" />
  </svg>
);
const RAIL_LOCK = (
  <svg viewBox="0 0 24 24" className="ds2-step__lock" aria-hidden>
    <rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
);
function stepGlyph(s: RailStep): ReactNode {
  switch (s.state) {
    case 'done': return RAIL_CHECK;
    case 'blocked': return '!';
    case 'deferred': return '–';
    case 'optional': return '○';
    default: return s.num ?? '•'; // current · wip · todo carry the step number
  }
}

/** State is a `state` ENUM per step (icon + colour) — there is no caption/description prop, so "Needs X" /
 *  "In progress" prose cannot be added. Per-phase `done/total` count is computed, not authored. `onStep`
 *  (optional) makes a step selectable — the step passes its own `key` back so the caller opens its editor. */
// AL-6 (S-UX-AUDIT-1) — `direction` (default 'auto'): horizontal ≥768px, vertical below (spec R-M2). The
// steps are wrapped in a `.ds2-rail__steps` container so the horizontal row is a pure CSS flip; no call site
// needs to know. A rail that must stay a vertical sidebar (the AdminProgrammes wizard nav) passes 'vertical'.
export function WizardRail({ phases, onStep, direction = 'auto' }: { phases: RailPhase[]; onStep?: (key: string) => void; direction?: 'auto' | 'horizontal' | 'vertical' }) {
  return (
    <div className={`ds2-rail ds2-rail--${direction}`}>
      {phases.map((ph, i) => {
        const done = ph.steps.filter((s) => s.state === 'done').length;
        return (
          <div key={i} className="ds2-rail__group">
            <div className="ds2-rail__phase">{ph.title}<span className="ds2-rail__ct">{done}/{ph.steps.length}</span></div>
            <div className="ds2-rail__steps">
            {ph.steps.map((s, j) => {
              const clickable = onStep && s.key !== undefined;
              return (
                <div
                  key={j}
                  className={`ds2-step ds2-step--${s.state}${clickable ? ' ds2-step--clickable' : ''}${s.selected ? ' ds2-step--sel' : ''}`}
                  role={clickable ? 'button' : undefined}
                  aria-pressed={clickable && s.selected !== undefined ? s.selected : undefined}
                  tabIndex={clickable ? 0 : undefined}
                  onClick={clickable ? () => onStep(s.key as string) : undefined}
                  onKeyDown={clickable ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onStep(s.key as string); } } : undefined}
                >
                  <span className="ds2-step__ic">{stepGlyph(s)}</span>
                  <span className="ds2-step__lbl">{s.label}</span>
                  {s.locked && RAIL_LOCK}
                </div>
              );
            })}
            </div>
          </div>
        );
      })}
    </div>
  );
}

// ── FormLanguageSwitcher — the ONLY trilingual primitive ────────────────────────────────────────────
export type Ds2Lang = 'en' | 'tc' | 'sc';

/** Form-level language switch + per-language completeness dots (a grey dot = that language has an
 *  incomplete field). The consuming form renders ONE plain input per field, bound to `value`. There is no
 *  per-field trilingual component in DS2 — so per-field EN/繁/简 triplets are un-buildable. Wraps antd
 *  Segmented; all text is caller-translated ReactNode. */
export function FormLanguageSwitcher({
  value,
  onChange,
  complete,
  labels,
  editingLabel,
  warning,
}: {
  value: Ds2Lang;
  onChange: (lang: Ds2Lang) => void;
  complete: Record<Ds2Lang, boolean>;
  labels: Record<Ds2Lang, ReactNode>;
  editingLabel: ReactNode;
  warning?: ReactNode;
}) {
  const opt = (l: Ds2Lang) => ({
    value: l,
    label: (
      <span className="ds2-lsw-opt">
        {labels[l]} <span className={`ds2-cdot${complete[l] ? ' ds2-cdot--filled' : ''}`} />
      </span>
    ),
  });
  return (
    <div className="ds2-langform">
      <span className="ds2-langform__label">{editingLabel}</span>
      <Segmented size="small" value={value} onChange={(v) => onChange(v as Ds2Lang)} options={[opt('en'), opt('tc'), opt('sc')]} />
      {warning}
    </div>
  );
}

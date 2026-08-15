// DS2 v3 record-SHELL primitive (P0-4). Pure, presentational, data-agnostic — the record-page ANATOMY from
// KAP-UIUX-Proposal.md Part C §C1 (header band · highlights strip · main + rail · history). GRAMMAR-FLEXIBLE:
// the SAME shell renders the family single-column grammar (§C2 — header + main only, other zones omitted) and
// the staff two-column grammar (§C3/§C4 — all zones). INVISIBLE until a surface adopts it (barrel + guard).
//
// The one behavioural rule a pure shell must encode is the iff-rule (A1) expressed STRUCTURALLY: a zone with no
// content is ABSENT, never an empty frame. No highlights → no strip; no rail → main spans full width; no
// history → no card. The adopting surface passes a zone iff the viewer's entitled reads returned its content —
// the shell never fabricates an empty box, so "unentitled = absent" holds by construction.
//   RecordShell      — §C1 anatomy: header (req) · highlights? · main (req) · rail? · history?
//   RecordHeaderBand — §C1 text header: eyebrow? · name · state? · identifiers? · primaryAction? · actions?
import type { ReactNode } from 'react';
import { Button } from 'antd';
import './shell.css';

// ── RecordHeaderBand (§C1) — the TEXT header band (distinct from the §3.7 image ProgrammeBandHeader) ──────
// Two invariants live in the API SHAPE, not in a surface's discipline:
//   ≤1 gold  — there is ONE `primaryAction` slot (gold); `actions` are default-tone. Two golds are unexpressible.
//   every button = a transition REQUEST — an action carries {label, onRequest}, never an href or free children,
//     so the header cannot host a raw link. `disabled`+`disabledReason` cover the ONE sanctioned disabled state
//     (four-eyes, §3.7: "You recorded this payment; a second officer must confirm").
export interface RecordAction { label: ReactNode; onRequest: () => void; disabled?: boolean; disabledReason?: string }

function actionButton(a: RecordAction, primary: boolean, key?: number) {
  return (
    <Button
      key={key}
      type={primary ? 'primary' : 'default'}
      disabled={a.disabled}
      title={a.disabled ? a.disabledReason : undefined}
      onClick={a.disabled ? undefined : a.onRequest}
    >
      {a.label}
    </Button>
  );
}

/** The §C1 text header: entity-type eyebrow (muted) · name (foreground) · primary state chip · key identifiers,
 *  actions clustered right. Serves BOTH grammars — staff (eyebrow + ids + actions) and the §C2 family identity
 *  header (name + a school chip as one identifier, no actions). Actions are transition requests, ≤1 gold. */
export function RecordHeaderBand({ eyebrow, name, state, identifiers = [], primaryAction, actions = [] }: {
  eyebrow?: ReactNode;
  name: ReactNode;
  state?: ReactNode;
  identifiers?: ReactNode[];
  primaryAction?: RecordAction;
  actions?: RecordAction[];
}) {
  const hasActions = primaryAction != null || actions.length > 0;
  return (
    <div className="ds2-rhdr">
      <div className="ds2-rhdr__id">
        {eyebrow != null && <span className="ds2-rhdr__eyebrow">{eyebrow}</span>}
        <div className="ds2-rhdr__nameline">
          <span className="ds2-rhdr__name">{name}</span>
          {state}
        </div>
        {identifiers.length > 0 && (
          <div className="ds2-rhdr__ids">
            {identifiers.map((id, i) => <span key={i} className="ds2-rhdr__id-item">{id}</span>)}
          </div>
        )}
      </div>
      {hasActions && (
        <div className="ds2-rhdr__actions">
          {actions.map((a, i) => actionButton(a, false, i))}
          {primaryAction && actionButton(primaryAction, true)}
        </div>
      )}
    </div>
  );
}

// ── Highlights strip (§C1) — 3–5 decision-evidence facts, value discriminated (no prose slot) ────────────
// HighlightValue mirrors GlanceValue: Text | Tag | UrgencyChip — a FACT, never a sentence. Enforced by shape,
// so a surface cannot narrate a highlight. The label is the fact's name; the value carries its one datum.
export type HighlightValue = { text: ReactNode } | { tag: ReactNode } | { chip: ReactNode };
export interface HighlightItem { label: ReactNode; value: HighlightValue }

function highlightValue(v: HighlightValue): ReactNode {
  if ('text' in v) return <span className="ds2-hl__val-text">{v.text}</span>;
  if ('tag' in v) return v.tag;
  return v.chip;
}

// ── RecordShell (§C1) — the grammar-flexible record layout ────────────────────────────────────────────────
/** header + [highlights strip] + body(main [+ rail]) + [history]. Omitted zones are ABSENT (no empty frame):
 *  no highlights → no strip; no rail → main is full-width single column (the §C2 family grammar); no history →
 *  no card. Presentational only — it never fetches, never decides entitlement; the caller passes a zone iff its
 *  reads returned content. */
export function RecordShell({ header, highlights, main, rail, history }: {
  header: ReactNode;
  highlights?: HighlightItem[];
  main: ReactNode;
  rail?: ReactNode;
  history?: ReactNode;
}) {
  const hasHighlights = highlights != null && highlights.length > 0;
  const hasRail = rail != null;
  return (
    <div className="ds2-record">
      <div className="ds2-record__header">{header}</div>
      {hasHighlights && (
        <div className="ds2-record__highlights" role="list">
          {highlights!.map((h, i) => (
            <div key={i} role="listitem" className="ds2-record__hl">
              <span className="ds2-hl__lbl">{h.label}</span>
              <span className="ds2-hl__val">{highlightValue(h.value)}</span>
            </div>
          ))}
        </div>
      )}
      <div className={`ds2-record__body${hasRail ? ' ds2-record__body--railed' : ''}`}>
        <div className="ds2-record__main">{main}</div>
        {hasRail && <aside className="ds2-record__rail">{rail}</aside>}
      </div>
      {history != null && <div className="ds2-record__history">{history}</div>}
    </div>
  );
}

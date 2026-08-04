// DS2 atom kit (STEP 2). Each atom ENFORCES its design rule by API SHAPE: the props are structured data,
// never a free-text prose slot — a page author cannot narrate where an atom belongs.
//   StatusAtom  — D5 hierarchy: SEPARATE loud `status` + demoted `detail`; no single "text" prop.
//   StatChip    — D7: a `value` + a `label` (a datum, not a sentence).
//   MetaChip    — D7: an optional icon + a (translated) value.
//   StateBadge  — D7: a `state` enum → a visual; the only text is an a11y `title`.
//   ProgressRing— D7: `value`/`total` NUMBERS → a ring.
//   DatedBadge  — D5+D7 (the honesty atom's date): a `date` VALUE + a short translated `label`, never a
//                 reassurance sentence. The full record lives behind the STEP-3 Attest's onViewRecord.
//   Seal        — the D3 attestation mark.
// i18n-native: all textual content arrives as already-translated ReactNode (caller owns t()); the atoms
// hold no hardcoded strings. On the --ka-* tokens (darkAlgorithm-native).
import type { CSSProperties, ReactNode } from 'react';
import { formatHktDate } from '../display/date';
import './atoms.css';

/** The D3 attestation seal. Decorative. */
export function Seal({ size = 20 }: { size?: number }) {
  return (
    <span className="ds2-seal" style={{ width: size, height: size }} aria-hidden>
      <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" /></svg>
    </span>
  );
}

export type BadgeState = 'sealed' | 'ok' | 'action' | 'warn';
/** A state as a VISUAL (D7). `title` is the a11y label only — there is no free-text content slot. */
export function StateBadge({ state, title }: { state: BadgeState; title?: string }) {
  const d = state === 'sealed' || state === 'ok' ? 'M20 6L9 17l-5-5' : 'M12 8v5M12 17h.01';
  return (
    <span
      className={`ds2-sbadge ds2-sbadge--${state}`}
      role={title ? 'img' : undefined}
      aria-label={title}
      aria-hidden={title ? undefined : true}
    >
      <svg viewBox="0 0 24 24"><path d={d} /></svg>
    </span>
  );
}

/** D5 hierarchy: a loud `status` + a demoted `detail`. Fixed weights, separate slots — equal-weight prose
 *  cannot be authored, and there is no single narrating-text prop. */
export function StatusAtom({
  status,
  detail,
  icon,
  tone = 'default',
}: {
  status: ReactNode;
  detail?: ReactNode;
  icon?: ReactNode;
  tone?: 'default' | 'attested' | 'action';
}) {
  return (
    <div className={`ds2-statusatom ds2-tone--${tone}`}>
      {icon && <span className="ds2-statusatom__icon" aria-hidden>{icon}</span>}
      <div>
        <div className="ds2-atom">{status}</div>
        {detail != null && <div className="ds2-subatom">{detail}</div>}
      </div>
    </div>
  );
}

/** A datum as an atom (D7): bold `value` + muted `label`. */
export function StatChip({ value, label }: { value: ReactNode; label: ReactNode }) {
  return <span className="ds2-statchip"><b>{value}</b> {label}</span>;
}

/** A meta value chip (D7): optional icon + the (translated) value. */
export function MetaChip({ icon, children }: { icon?: ReactNode; children: ReactNode }) {
  return (
    <span className="ds2-chip">
      {icon && <span className="ds2-chip__i" aria-hidden>{icon}</span>}
      {children}
    </span>
  );
}

/** Readiness as a visual (D7): `value`/`total` are NUMBERS. */
export function ProgressRing({ value, total, label }: { value: number; total: number; label?: ReactNode }) {
  const pct = total > 0 ? Math.round((value / total) * 100) : 0;
  return (
    <span
      className="ds2-ring"
      style={{ '--p': pct } as CSSProperties}
      role="img"
      aria-label={typeof label === 'string' ? label : `${value} of ${total}`}
    >
      <b>{label ?? <>{value}/{total}</>}</b>
    </span>
  );
}

const CAL = (
  <span className="ds2-chip__i" aria-hidden>
    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
  </span>
);
/** The honesty atom's DATE (D5+D7): a `date` VALUE + a short translated `label` ("Signed"). It renders the
 *  localized date — it accepts NO reassurance sentence. Detail-on-demand belongs to the STEP-3 Attest. */
export function DatedBadge({
  label,
  date,
  locale,
  icon,
}: {
  label: ReactNode;
  date: string;
  locale: string;
  icon?: ReactNode;
}) {
  return (
    <span className="ds2-chip">
      {icon ? <span className="ds2-chip__i" aria-hidden>{icon}</span> : CAL}
      {label} {formatHktDate(date, locale)}
    </span>
  );
}

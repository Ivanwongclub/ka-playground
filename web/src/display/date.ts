// S-UX2a — the ONE date/time formatter. Timestamps are stored UTC and rendered HKT
// (Asia/Hong_Kong), locale-aware. Null-safe (→ —). Kills the inconsistent inline copies
// (some en-GB, some not locale-aware) and every raw-ISO leak.

// The API emits timestamps like "2026-07-29 17:27:54+00" — normalise the space and the
// bare "+00" offset so every engine parses it.
function parse(v: string): Date {
  const norm = v.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00');
  return new Date(norm);
}

/** Full timestamp in HKT, e.g. "2 Aug 2026, 17:27". */
export function formatHkt(iso: string | null | undefined, locale: string): string {
  if (!iso) return '—';
  const d = parse(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat(locale, {
    timeZone: 'Asia/Hong_Kong',
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(d);
}

/** Time only in HKT, e.g. "19:27". S-FIX-UX-1 D5 — for a session's end time beside its start. */
export function formatHktTime(iso: string | null | undefined, locale: string): string {
  if (!iso) return '—';
  const d = parse(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat(locale, {
    timeZone: 'Asia/Hong_Kong',
    timeStyle: 'short',
  }).format(d);
}

/**
 * Date only. A bare calendar date (YYYY-MM-DD, e.g. a formation deadline) is formatted as
 * a calendar date with NO timezone shift; a full timestamp is rendered as its HKT date.
 */
export function formatHktDate(v: string | null | undefined, locale: string): string {
  if (!v) return '—';
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(v.trim());
  const d = dateOnly ? new Date(`${v.trim()}T00:00:00`) : parse(v);
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat(
    locale,
    dateOnly ? { dateStyle: 'medium' } : { timeZone: 'Asia/Hong_Kong', dateStyle: 'medium' },
  ).format(d);
}

/**
 * Day + month only, HKT — "21 Aug". The deadline treatment on an action-required row (B2-GUA-HOME) needs
 * the SHORT form the prototype uses: a full medium date ("Aug 21, 2026") squeezes the row title on a 390px
 * phone hard enough to break a money value mid-number. Year-less by design: these deadlines are days away.
 */
export function formatHktDayMonth(v: string | null | undefined, locale: string): string {
  if (!v) return '\u2014';
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(v.trim());
  const d = dateOnly ? new Date(`${v.trim()}T00:00:00`) : parse(v);
  if (Number.isNaN(d.getTime())) return '\u2014';
  return new Intl.DateTimeFormat(
    locale,
    dateOnly ? { day: 'numeric', month: 'short' } : { timeZone: 'Asia/Hong_Kong', day: 'numeric', month: 'short' },
  ).format(d);
}

/**
 * Sort key for a pg timestamptz or bare date (ms since epoch), reusing the same `parse` normaliser as the
 * formatters above (NOT a hand-rolled Date.parse). null / undefined / unparseable → +Infinity so a missing
 * deadline sorts LAST under ascending order. Display-only ordering (P0-SAFE-2, Proposal Part D-b) — never
 * rendered; use formatHkt* for display. The +Infinity-on-NaN guard keeps it safe inside a comparator.
 */
export function tsSort(s: string | null | undefined): number {
  if (!s) return Number.POSITIVE_INFINITY;
  const t = parse(s).getTime();
  return Number.isNaN(t) ? Number.POSITIVE_INFINITY : t;
}

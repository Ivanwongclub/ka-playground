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

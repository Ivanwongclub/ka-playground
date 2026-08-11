// DS2 v2 (R0-B1) — the ONE urgency helper (DS2-V2-SPEC §3). A PURE function: a deadline + per-domain
// thresholds → an UrgencyLevel. The thresholds live with the DOMAIN (parameters here); the visual
// treatment lives with DS2 (the UrgencyChip + .ds2-urgent row rules). No surface invents its own overdue
// colour — it computes a level and hands it to the DS2 treatment.
//
// Derivation (spec §3): delta = deadline − now
//   overdue : delta < 0
//   due     : delta ≤ dueWithin   (and ≥ 0)
//   soon    : delta ≤ soonWithin
//   none    : otherwise

export type UrgencyLevel = 'none' | 'soon' | 'due' | 'overdue';

/** Windows measured in milliseconds from `now`. */
export interface UrgencyThresholds {
  soonWithin: number;
  dueWithin: number;
}

const DAY = 86_400_000;

// Static per-domain presets (spec §3). Exported so a surface passes the SAME thresholds everywhere.
export const URGENCY: { consent: UrgencyThresholds; payment: UrgencyThresholds } = {
  consent: { soonWithin: 7 * DAY, dueWithin: 2 * DAY },
  payment: { soonWithin: 5 * DAY, dueWithin: 1 * DAY },
};

/**
 * Approvals are threshold-driven (the queue carries `threshold_days`), so their windows are computed
 * from it rather than fixed: soonWithin = (threshold_days − 2) days, dueWithin = 0 — i.e. at/over the
 * threshold is overdue. A factory, not a constant, because the domain parameter is runtime data.
 */
export function approvalThresholds(thresholdDays: number): UrgencyThresholds {
  return { soonWithin: Math.max(0, thresholdDays - 2) * DAY, dueWithin: 0 };
}

// The API emits timestamps like "2026-07-29 17:27:54+00" — normalise the space and bare "+00" offset so
// every engine parses it (mirrors display/date.ts `parse`; kept local so urgency has no date-format dep).
function parseTs(v: string): number {
  return Date.parse(v.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
}

/** PURE: the urgency level of a deadline given the domain's thresholds. `null`/unparseable → 'none'. */
export function urgencyLevel(
  deadline: string | null | undefined,
  thresholds: UrgencyThresholds,
  nowMs: number = Date.now(),
): UrgencyLevel {
  if (!deadline) return 'none';
  const t = parseTs(deadline);
  if (Number.isNaN(t)) return 'none';
  const delta = t - nowMs;
  if (delta < 0) return 'overdue';
  if (delta <= thresholds.dueWithin) return 'due';
  if (delta <= thresholds.soonWithin) return 'soon';
  return 'none';
}

/** Signed whole-day countdown to a deadline (ceil): +N days until, −N days overdue. `null`→0. Used to
 *  drive the shared label; the sign never lands on 0 for a passed deadline (overdue is at least −1). */
export function urgencyDays(deadline: string | null | undefined, nowMs: number = Date.now()): number {
  if (!deadline) return 0;
  const t = parseTs(deadline);
  if (Number.isNaN(t)) return 0;
  const delta = t - nowMs;
  return delta >= 0 ? Math.ceil(delta / DAY) : -Math.ceil(-delta / DAY);
}

/**
 * Approvals carry no raw deadline timestamp — only age_days + threshold_days. Compute the level from
 * those integers, with windows matching approvalThresholds() exactly (overdue past the threshold, due
 * AT it, soon within 2 days of it). The signed day-count for the label is (thresholdDays − ageDays).
 */
export function approvalLevel(ageDays: number, thresholdDays: number): UrgencyLevel {
  const { soonWithin, dueWithin } = approvalThresholds(thresholdDays);
  const deltaMs = (thresholdDays - ageDays) * DAY;
  if (deltaMs < 0) return 'overdue';
  if (deltaMs <= dueWithin) return 'due';
  if (deltaMs <= soonWithin) return 'soon';
  return 'none';
}

/**
 * The ONE shared countdown label (all three surfaces — no per-surface variants). `days` is the signed
 * whole-day count (positive = until, 0 = today, negative = overdue). Pluralised via i18next's reserved
 * `count` option (JSON-v4 _one/_other suffixes; the checker's family-collapse keeps zh CLDR-honest).
 */
export function urgencyLabel(
  level: UrgencyLevel,
  days: number,
  t: (key: string, opts?: Record<string, unknown>) => string,
): string {
  if (level === 'overdue') return t('urgency.overdueDays', { count: Math.abs(days) });
  if (days <= 0) return t('urgency.dueToday');
  return t('urgency.inDays', { count: days });
}

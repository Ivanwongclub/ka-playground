// S-UX2a — id→name display helpers, consuming the S-UX2b API fields. A raw FK integer
// never reaches the screen: a name resolves, or a neutral placeholder shows (never the id).

import type { KaLocale } from '../i18n';

interface HasProgrammeNames {
  programme_name_en?: string | null;
  programme_name_tc?: string | null;
  programme_name_sc?: string | null;
}

/** Localised programme name from the S-UX2b triple; falls back across languages, then —. */
export function programmeName(row: HasProgrammeNames, locale: KaLocale): string {
  const byLocale =
    locale === 'zh-TC' ? row.programme_name_tc : locale === 'zh-SC' ? row.programme_name_sc : row.programme_name_en;
  return byLocale ?? row.programme_name_en ?? row.programme_name_tc ?? row.programme_name_sc ?? '—';
}

interface HasSchoolNames {
  school_name_en?: string | null;
  school_name_tc?: string | null;
  school_name_sc?: string | null;
}

/** Localised school name from the school_name triple (S-READ-2), falling back across languages; null when the
 *  child sits on no active school link (a direct-to-academy student) — the caller omits the line, never "—". */
export function schoolName(row: HasSchoolNames, locale: KaLocale): string | null {
  const byLocale =
    locale === 'zh-TC' ? row.school_name_tc : locale === 'zh-SC' ? row.school_name_sc : row.school_name_en;
  return byLocale ?? row.school_name_en ?? row.school_name_tc ?? row.school_name_sc ?? null;
}

/** A person's display name, or a neutral placeholder — never a raw id. */
export function personName(name: string | null | undefined): string {
  return name && name.trim() ? name : '—';
}

/**
 * Two-letter initials for an avatar — never a raw id. ONE definition (B3-GUA-CHILD): this had drifted into
 * FOUR copies — display/team.tsx's memberInitials plus a local `initials` in Profile360, GuardianHome and
 * (nearly) this card's page. The null-safe form from team.tsx is the superset and is what survives; every
 * caller now shares it.
 *
 * BEHAVIOUR DELTA, stated rather than smuggled: for the two page callers an EMPTY name previously rendered
 * an empty avatar (''.toUpperCase()); it now renders '—', the placeholder the roster already used. Identical
 * for every non-empty name, which is every case where a name resolves.
 */
export function initials(name: string | null | undefined): string {
  const n = (name ?? '').trim();
  if (!n) return '—';
  const parts = n.split(/\s+/).filter(Boolean);
  return (parts.length >= 2 ? parts[0][0] + parts[1][0] : n.slice(0, 2)).toUpperCase();
}

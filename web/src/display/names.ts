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

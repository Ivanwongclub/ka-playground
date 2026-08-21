// C8-TEAM — the single definition of the team-formation display primitives, shared by the student surface
// (/my/team, StudentTeam.tsx), the enrolment-scoped Team tab (EnrolmentSpace.tsx) and the record lens
// (Profile360.tsx). Extracted so the ROLE-as-plain-text discipline lives in ONE place: a tenure role is an
// INFORMATIVE value, so it renders as right-aligned muted text — never a pill, never gold (Kit §3.1: pills mark
// actionable/attention states; A5: gold marks action/position, not a value). This corrects the prior gold-Tag
// role render (audit S1 N-5).
import { Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useResource } from '../api/useResource';

const { Text } = Typography;

export interface Tri { name_en: string; name_tc: string; name_sc: string }
export interface TeamRow {
  id: string; programme_id: number; name: string; status: string; member_count: number;
  created_by?: number | null; // the submitter (teams.created_by); client-side submit gate keys on it
  programme_name_en?: string | null; programme_name_tc?: string | null; programme_name_sc?: string | null;
  category_id?: string | null;
}
export interface RosterMember { student_id: number; student_name: string | null; role: Tri | null }
export interface Roster { team_id: string; member_count: number; members: RosterMember[] | null }
export interface Lobby { id: string; name_en: string; name_tc: string; name_sc: string; school_bound: boolean; eligible: boolean }

/** Localised name from a trilingual triple, falling back across languages then to an em dash. */
export function tri(o: Tri | null, locale: KaLocale): string {
  if (!o) return '—';
  return (locale === 'zh-TC' ? o.name_tc : locale === 'zh-SC' ? o.name_sc : o.name_en) || o.name_en;
}

/** Two-letter initials for a roster avatar — never a raw id. B3-GUA-CHILD: the implementation moved to
 *  display/names.ts as the ONE definition (it had drifted into four copies); this alias keeps the team
 *  module's API unchanged for its existing callers. */
export { initials as memberInitials } from './names';

/** A member's tenure role as INFORMATIVE right-aligned text — no pill, no gold (single definition; see header). */
export function MemberRole({ role, locale }: { role: Tri | null; locale: KaLocale }) {
  if (!role) return null;
  return <Text type="secondary">{tri(role, locale)}</Text>;
}

/** A joinable team's COUNT ONLY (B2 count-only branch — never teammate names pre-join). */
export function JoinableCount({ teamId }: { teamId: string }) {
  const { t } = useTranslation();
  const roster = useResource<Roster>(`/api/teams/${teamId}/members`);
  if (roster.loading || roster.data == null) return null;
  return <Tag>{t('studentTeam.members', { n: roster.data.member_count })}</Tag>;
}

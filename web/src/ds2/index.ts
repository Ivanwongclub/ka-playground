// @/ds2 — the single design import root (Design System v2).
//
// STEP 1 (tokens/theme): importing this brings the --ka-* token layer and re-exports the shared display /
// data helpers, so every future restyle imports design from ONE place. The atom kit arrives in STEP 2 and
// the structure primitives in STEP 3 — added to this barrel then.
//
// RULING 2 (changes-nothing-yet): NOTHING outside web/src/ds2/ (and, from STEP 2, the dev-only gallery) may
// import this barrel. scripts/ds2-import-guard.mjs FAILS the build otherwise. So no built surface has adopted
// DS2 yet, and every existing money / consent / child-data page renders byte-identical after this lands.
// Adopting DS2 is a DELIBERATE act — a rollout card adds its surface to the guard's allowlist in its slot.
import './tokens.css';

// The atom kit (STEP 2) — each enforces its rule by API shape (structured props, no free-text slot).
export { StatusAtom, StatChip, MetaChip, StateBadge, ProgressRing, DatedBadge, Seal } from './atoms';
export type { BadgeState } from './atoms';

// The structure primitives (STEP 3) — zones (SubPanel/ZoneStack), the honesty component (Attest), the dense
// table (ZebraTable), the stepper (WizardRail) and the ONLY trilingual pattern (FormLanguageSwitcher).
export { SubPanel, ZoneStack, StatCard, Attest, ZebraTable, WizardRail, FormLanguageSwitcher } from './structure';
export type { Tone, Ds2Column, Ds2ColType, StepState, RailStep, RailPhase, Ds2Lang } from './structure';

// DS2 v2 surface primitives (R0-B1) — standalone card (PageCard/AuthCard), imagery (HeroBanner), the
// action-first home unit (TaskCard), the designed zero-state (EmptyState) and the urgency chip (§2/§3).
export { PageCard, AuthCard, HeroBanner, TaskCard, EmptyState, UrgencyChip } from './surfaces';
export type { PageCardWidth, Ds2Cta } from './surfaces';

// DS2 v3 record/composition primitives (P0-3a) — pure/presentational, invisible until a surface adopts them.
export { Ds2SegBar, GlanceCard, ProgrammeBandHeader, JourneyStepper } from './records';
export type { SegState, SegItem, GlanceValue, GlanceRow, JourneyStep, JourneyTile } from './records';

// DS2 v3 interaction primitives (P0-3b) — pure/presentational, data-agnostic (take props, never fetch).
export { Board, ActionRequiredList, OverviewTabs, ElasticSearch } from './interactions';
export type {
  BoardColumn, BoardItem, ActionItem, OverviewCol, OverviewRow, OverviewItemTab, SearchGroup, SearchResult,
} from './interactions';
// BottomSheet (§3.11) — PROMOTED into the barrel from components/mobile (re-export only; file/logic unchanged,
// so StyleGuide's existing direct import stays byte-identical). Matches how the barrel re-exports StatusTag etc.
export { BottomSheet } from '../components/mobile/BottomSheet';

// The pure urgency helpers + per-domain thresholds (§3) — a level from a deadline (or from approvals'
// age/threshold integers), the signed day-count, and the ONE shared countdown label; treatment is DS2-owned.
export { urgencyLevel, urgencyDays, approvalLevel, approvalThresholds, urgencyLabel, URGENCY } from '../display/urgency';
export type { UrgencyLevel, UrgencyThresholds } from '../display/urgency';

// Status pills + money/date/name formatting — the existing display layer, re-exported (not reinvented).
export { StatusTag, AuditCode, humanise } from '../display/status';
export { formatMoney } from '../display/money';
export { formatHkt, formatHktDate } from '../display/date';
export { personName, programmeName } from '../display/names';

// Data plumbing the design surfaces use.
export { useResource, DataBoundary } from '../api/useResource';
export { mutate } from '../api/mutate';
export type { MutateResult } from '../api/mutate';

// Palette constants for the bespoke DS2 components (category accents, seal) — STEP 2/3 consume these.
export { kaColors, kaCategoryAccents } from '../theme/theme';

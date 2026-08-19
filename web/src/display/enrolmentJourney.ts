// C5-CONSUME — the enrolment state model, ONE definition (was duplicated in StudentProgrammes + EnrolmentSpace;
// same single-definition discipline as isStudentActor / tsSort). Consumed by the /programmes segbar and the
// scoped-space Journey stepper.
//
// 7 real enrolment states → 5 display steps (the C2-LIST fold): pending_consent folds under Submitted; completed
// under Active (the terminal end). withdrawn + released carry NO stepper/segbar — the status pill says it.
//
// NB 'released' is a GENUINE TERMINAL ENROLMENT status — EnrolmentService has in_pool → released as a real
// transition and treats it as terminal (`'released' => []`), and enr_status_check admits it. Its name coincides
// with the assessment-domain 'released' (results embargo lift), but for enrolments it is legitimate. DO NOT
// remove it from TERMINAL_BAD — a released enrolment must render its status pill, never a 5-step journey.

// This module deliberately does NOT import from @/ds2 (a display util is not a DS2 adopter). The segment shape
// is structural and assignable to the primitive's SegItem where consumed.
type SegState = 'done' | 'current' | 'todo';
export interface JourneySeg { label: string; state: SegState }

// The 5 display steps, in order, keyed by their enrolCard.seg.* i18n label.
export const SEG = ['submitted', 'pool', 'teamed', 'confirmed', 'active'] as const;
const FOLD: Record<string, number> = { submitted: 0, pending_consent: 0, in_pool: 1, teamed: 2, confirmed: 3, active: 4, completed: 4 };
export const TERMINAL_BAD = ['withdrawn', 'released'];
// status → whatNext i18n key (§3.4, the one sanctioned prose slot). Terminal states have none.
export const WHATNEXT: Record<string, string> = { submitted: 'submitted', pending_consent: 'submitted', in_pool: 'in_pool', teamed: 'teamed', confirmed: 'confirmed', active: 'active', completed: 'completed' };

export function isTerminal(status: string): boolean {
  return TERMINAL_BAD.includes(status);
}

/** The current display-step index (0..4); completed = SEG.length so the antd stepper reads all-finished. */
export function currentIndex(status: string): number {
  return status === 'completed' ? SEG.length : (FOLD[status] ?? 0);
}

/** Segment states for the Ds2SegBar — or undefined for a terminal-bad status (no bar; the status pill carries it). */
export function segItems(status: string, t: (k: string) => string): JourneySeg[] | undefined {
  if (isTerminal(status)) return undefined;
  const cur = FOLD[status] ?? 0;
  const done = status === 'completed'; // terminal end — the whole journey reads as done
  return SEG.map((lbl, i) => ({
    label: t(`enrolCard.seg.${lbl}`),
    state: done || i < cur ? 'done' : i === cur ? 'current' : 'todo',
  }));
}

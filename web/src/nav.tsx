// S-UX1 — the single source of navigation, grouped and permission-gated. Each item's
// `visible` predicate mirrors the SERVER gate on its endpoint (derived from routes/api.php
// + the FinancialIntegrityReportController in-controller gate; NO guessed gates):
//   Enrolments          → enrolment.view
//   Consents            → consent.view
//   Programmes/Templates → configuration.manage
//   Enrolment Pool / Access & Identity / Audit / Consent Evidence → audit.read
//   Financial Integrity → finance.record OR audit.read  (never finance.view — that is a
//                         role default guardians/schools hold for their OWN money)
// Stub screens (Tracker/Team/Learn/Profile) are intentionally ABSENT (D-UX1.1): the nav
// offers only working screens; each S-UX3 domain card reveals its own item when it lands.
import {
  BookOpen,
  ClipboardCheck,
  CreditCard,
  FileCheck,
  FileSignature,
  FileText,
  GraduationCap,
  LayoutDashboard,
  LogOut,
  ShieldCheck,
  Undo2,
  Users,
  Users2,
  CalendarDays,
  Contact,
  IdCard,
  CalendarCheck,
  NotebookPen,
  Baby,
  Wallet,
  UsersRound,
  Store,
} from 'lucide-react';
import type { ReactNode } from 'react';

export type Has = (permission: string) => boolean;

// THE student-actor signature — ONE definition (P0-SAFE-1). enrolment.view ∧ events.rsvp uniquely mark a student
// across the seeded roles EXCEPT super_admin (holds both via '*'); ¬operations.manage excludes super. Consumed by
// the /my/team nav gate below, StudentTeam.tsx's deep-link guard, and Consents/Dashboard/Enrolments — inlining
// the 3-term expression in more than one place is the exact drift class this card fixes, so it lives here beside
// `Has` (its parameter type) and every caller imports it: the literal appears exactly once, in this function.
export function isStudentActor(has: Has): boolean {
  return has('enrolment.view') && has('events.rsvp') && !has('operations.manage');
}

// THE guardian-actor signature — ONE definition (C1-SHELL). consent.sign is GUARDIAN-UNIQUE: it is a guardian-role
// permission AND listed in capability_forbidden, so no capability group (not even super_admin's '*') can carry it.
// So `has('consent.sign')` alone identifies a guardian — no ¬super qualifier needed, unlike isStudentActor. Read
// it as "IS the guardian", not "can sign": every guardian-identifying caller imports it (the nav gates, the
// /enrolments ¬guardian demotion, and Dashboard/Enrolments/Marketplace/Consents) so the literal appears once.
export function isGuardianActor(has: Has): boolean {
  return has('consent.sign');
}

export interface NavLeaf {
  path: string;
  i18nKey: string; // nav.*
  icon: ReactNode;
  visible?: (has: Has) => boolean; // omitted ⇒ always visible to an authenticated user
}

export interface NavGroup {
  i18nKey: string; // navGroup.*
  items: NavLeaf[];
}

export const NAV: NavGroup[] = [
  {
    i18nKey: 'navGroup.overview',
    items: [{ path: '/', i18nKey: 'nav.dashboard', icon: <LayoutDashboard size={16} aria-hidden /> }],
  },
  {
    // R1-P360 — order carries each role's ACTIVE lens (records live in Profile / My Children).
    //   Student : My Team · My Sessions · My Profile
    //   Guardian: My Children · Consent · My Payments · Enrolments · My Child's Sessions
    //   Teacher : Enrolments · Attendance · My Students
    i18nKey: 'navGroup.programme',
    items: [
      {
        // C1-SHELL — the STUDENT "Programmes" list: the enrolment-card front door to the scoped programme space
        // (/enrolments/:enrolmentId, D-1 keyed on enrolment_id). Route is an EMPTY Placeholder skeleton this card;
        // the enrolment-card list is the next card. Student-only via isStudentActor. (Was: My Team · My Sessions,
        // both DEMOTED out of nav — routes stay reachable — per B1's 4-slot student nav.)
        path: '/programmes',
        i18nKey: 'nav.myProgrammes',
        icon: <BookOpen size={16} aria-hidden />,
        visible: isStudentActor,
      },
      {
        // KAP-MKT-1 — the STUDENT Market Place (browse + R-2 status; enrolment is guardian-led). events.rsvp is
        // the student signature (a member lacks enrolment.view; a guardian/teacher/ops lacks events.rsvp).
        path: '/marketplace',
        i18nKey: 'nav.marketplace',
        icon: <Store size={16} aria-hidden />,
        visible: (h) => h('enrolment.view') && h('events.rsvp'),
      },
      {
        // C1-SHELL — the STUDENT "Me" (B1 slot name; relabelled nav.myProfile → nav.me — "My Profile" was the
        // record-page framing that made the student's Me wrong, S2 G-9). Still /my/profile → Profile360 self-view;
        // student-only via events.rsvp. (The guardian's "Me" is /me below — a different route, same slot.)
        path: '/my/profile',
        i18nKey: 'nav.me',
        icon: <IdCard size={16} aria-hidden />,
        visible: (h) => h('enrolment.view') && h('events.rsvp'),
      },
      {
        // S-UX3-9 — GUARDIAN "Children". isGuardianActor (consent.sign is guardian-unique — capability_forbidden
        // bars every capability group incl. super's '*'; students hold consent.view, not .sign).
        path: '/my/children',
        i18nKey: 'nav.myChildren',
        icon: <Baby size={16} aria-hidden />,
        visible: isGuardianActor,
      },
      {
        // R1-P360 — Consent is the GUARDIAN'S act. ¬events.rsvp keeps it off the student menu; guardian and
        // school_admin/ops (all ¬events.rsvp, all hold consent.view) keep it.
        path: '/consents',
        i18nKey: 'nav.consents',
        icon: <FileSignature size={16} aria-hidden />,
        visible: (h) => h('consent.view') && !h('events.rsvp'),
      },
      {
        // S-UX3-9 — GUARDIAN "Payments" (read-only obligations/receipts + get-payment-link). isGuardianActor
        // keeps it guardian-only (a guardian's finance.view is "their own money"), not school_admin/ops.
        path: '/my/payments',
        i18nKey: 'nav.myPayments',
        icon: <Wallet size={16} aria-hidden />,
        visible: isGuardianActor,
      },
      {
        // C1-SHELL — the GUARDIAN "Me" (B2's 5th slot). NO correct target exists yet: Profile360 is the STUDENT
        // grammar (S2 G-9). So this is an EMPTY Placeholder skeleton, present so the slot RENDERS in the mobile
        // tab bar — fixing S2 G-2 (guardian Me never rendered: it was the 6th of 7 leaves, cut by slice(0,5)).
        // The real guardian-account surface (identity · language · notification prefs · "Link another child",
        // prototype gua-me) is a later card. isGuardianActor.
        path: '/me',
        i18nKey: 'nav.me',
        icon: <IdCard size={16} aria-hidden />,
        visible: isGuardianActor,
      },
      {
        // R1-P360 — Enrolments (the flat operational table). C1-SHELL DEMOTES it out of the GUARDIAN nav (the
        // guardian's enrolments live in the scoped space via Children, not this table). `¬isGuardianActor` drops
        // EXACTLY the guardian (consent.sign is guardian-unique) and KEEPS teacher + school_admin, who use the
        // operational table. Route stays reachable. (¬events.rsvp already excludes student/super.)
        path: '/enrolments',
        i18nKey: 'nav.enrolments',
        icon: <GraduationCap size={16} aria-hidden />,
        visible: (h) => h('enrolment.view') && !h('events.rsvp') && !isGuardianActor(h),
      },
      {
        // S-UX3-4 — teacher (mentor) "Attendance" (roster → mark). teams.approve is held by teacher + ops;
        // excluding operations.manage leaves the teacher. Matches the role:teacher gate on /my/mentor/sessions.
        path: '/attendance',
        i18nKey: 'nav.attendance',
        icon: <CalendarCheck size={16} aria-hidden />,
        visible: (h) => h('teams.approve') && !h('operations.manage'),
      },
      {
        // S-UX3-9 — teacher "My Students" (school roll). teams.approve ∧ ¬operations.manage = teacher
        // (matches the S-UX3-4 mentor gate; both are teacher surfaces).
        path: '/my/students',
        i18nKey: 'nav.myStudents',
        icon: <UsersRound size={16} aria-hidden />,
        visible: (h) => h('teams.approve') && !h('operations.manage'),
      },
    ],
  },
  {
    // S-UX3-8 — the Member (Kings Network) surfaces. Gated member_directory.view (member + academy_admin
    // base); the reads/writes are member-scoped server-side, so an academy admin sees an empty/creatable
    // state rather than another member's data.
    i18nKey: 'navGroup.community',
    items: [
      { path: '/events', i18nKey: 'nav.events', icon: <CalendarDays size={16} aria-hidden />, visible: (h) => h('member_directory.view') },
      { path: '/directory', i18nKey: 'nav.directory', icon: <Contact size={16} aria-hidden />, visible: (h) => h('member_directory.view') },
      { path: '/profile', i18nKey: 'nav.profile', icon: <IdCard size={16} aria-hidden />, visible: (h) => h('member_directory.view') },
    ],
  },
  {
    // R1-P360 — ops items in queue-frequency order: Approvals · Team · Attendance Oversight · Withdrawals
    // · Programmes · Consent Templates (Withdrawals dropped below the daily-touch queues). Finance & audit
    // items below are UNCHANGED in gate and relative order.
    i18nKey: 'navGroup.administration',
    items: [
      {
        path: '/admin/approvals',
        i18nKey: 'nav.approvals',
        icon: <ClipboardCheck size={16} aria-hidden />,
        visible: (h) => h('operations.manage'),
      },
      {
        // S-UX3-3a — the ops Team Formation view. Gated on operations.manage like its sibling ops queues;
        // lobby school-admins retain direct server access (OD-39) under shown-not-hidden.
        path: '/team',
        i18nKey: 'nav.team',
        icon: <Users2 size={16} aria-hidden />,
        visible: (h) => h('operations.manage'),
      },
      {
        // S-UX3-4 / S-FIX-UX-1 D7 — ops attendance oversight (programme → report → roster + mark).
        // Gated operations.manage, mirroring the server: the picker now reads
        // /api/admin/attendance/programmes (ProgrammeController@opsOptions), an operations.manage
        // route, so an operations-only admin has a programme-list source.
        path: '/admin/attendance',
        i18nKey: 'nav.attendanceOversight',
        icon: <NotebookPen size={16} aria-hidden />,
        visible: (h) => h('operations.manage'),
      },
      {
        path: '/admin/withdrawals',
        i18nKey: 'nav.withdrawals',
        icon: <LogOut size={16} aria-hidden />,
        visible: (h) => h('operations.manage'),
      },
      {
        path: '/admin/programmes',
        i18nKey: 'nav.programmes',
        icon: <BookOpen size={16} aria-hidden />,
        visible: (h) => h('configuration.manage'),
      },
      {
        path: '/admin/consent-templates',
        i18nKey: 'nav.consentTemplates',
        icon: <FileText size={16} aria-hidden />,
        visible: (h) => h('configuration.manage'),
      },
      {
        path: '/admin/enrolment-pool',
        i18nKey: 'nav.enrolmentPool',
        icon: <Users size={16} aria-hidden />,
        visible: (h) => h('audit.read'),
      },
      {
        path: '/admin/payments',
        i18nKey: 'nav.payments',
        icon: <CreditCard size={16} aria-hidden />,
        visible: (h) => h('finance.record') || h('finance.confirm'),
      },
      {
        path: '/admin/refunds',
        i18nKey: 'nav.refunds',
        icon: <Undo2 size={16} aria-hidden />,
        visible: (h) => h('finance.record') || h('finance.confirm'),
      },
      {
        path: '/admin/financial-integrity',
        i18nKey: 'nav.financialIntegrity',
        icon: <FileCheck size={16} aria-hidden />,
        visible: (h) => h('finance.record') || h('audit.read'),
      },
      {
        path: '/admin/access-identity',
        i18nKey: 'nav.accessIdentity',
        icon: <Users size={16} aria-hidden />,
        visible: (h) => h('audit.read'),
      },
      {
        path: '/admin/audit',
        i18nKey: 'nav.audit',
        icon: <ShieldCheck size={16} aria-hidden />,
        visible: (h) => h('audit.read'),
      },
      {
        path: '/admin/consent-evidence',
        i18nKey: 'nav.consentEvidence',
        icon: <FileCheck size={16} aria-hidden />,
        visible: (h) => h('audit.read'),
      },
    ],
  },
];

/** Groups with only the items this caller may see; empty groups dropped. */
export function visibleGroups(has: Has): NavGroup[] {
  return NAV.map((g) => ({ ...g, items: g.items.filter((it) => !it.visible || it.visible(has)) })).filter(
    (g) => g.items.length > 0,
  );
}

/** Flat list of every visible leaf — for the mobile tab bar and breadcrumb resolution. */
export function visibleLeaves(has: Has): NavLeaf[] {
  return visibleGroups(has).flatMap((g) => g.items);
}

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
  UserPlus,
  CalendarDays,
  Contact,
  IdCard,
  CalendarClock,
  CalendarCheck,
  NotebookPen,
  Baby,
  Wallet,
  UsersRound,
} from 'lucide-react';
import type { ReactNode } from 'react';

export type Has = (permission: string) => boolean;

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
    i18nKey: 'navGroup.programme',
    items: [
      {
        path: '/enrolments',
        i18nKey: 'nav.enrolments',
        icon: <GraduationCap size={16} aria-hidden />,
        visible: (h) => h('enrolment.view'),
      },
      {
        path: '/consents',
        i18nKey: 'nav.consents',
        icon: <FileSignature size={16} aria-hidden />,
        visible: (h) => h('consent.view'),
      },
      {
        // S-UX3-3b — the student team-formation surface. teams.view is the student role default; hidden
        // from academy ops (operations.manage) who use the ops /team instead — shown-not-hidden, no dual nav.
        path: '/my/team',
        i18nKey: 'nav.myTeam',
        icon: <UserPlus size={16} aria-hidden />,
        visible: (h) => h('teams.view') && !h('operations.manage'),
      },
      {
        // S-UX3-4 — student "My Sessions" (book/cancel + own attendance). enrolment.view ∩ events.rsvp
        // uniquely identifies a student: a member lacks enrolment.view; a guardian/teacher/ops lacks
        // events.rsvp. Matches the role:student gate on /my/sessions.
        path: '/my/sessions',
        i18nKey: 'nav.mySessions',
        icon: <CalendarClock size={16} aria-hidden />,
        visible: (h) => h('enrolment.view') && h('events.rsvp'),
      },
      {
        // S-UX3-4 — guardian "My Child's Sessions" (read-only). consent.sign is guardian-unique
        // (capability_forbidden bars academy admins; students hold consent.view, not .sign).
        path: '/family/sessions',
        i18nKey: 'nav.childSessions',
        icon: <CalendarDays size={16} aria-hidden />,
        visible: (h) => h('consent.sign'),
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
        // S-UX3-9 — guardian "My Children". consent.sign is guardian-unique (capability_forbidden bars
        // ops; students hold consent.view, not .sign).
        path: '/my/children',
        i18nKey: 'nav.myChildren',
        icon: <Baby size={16} aria-hidden />,
        visible: (h) => h('consent.sign'),
      },
      {
        // S-UX3-9 — guardian "My Payments" (read-only obligations/receipts + get-payment-link). Guardian-
        // scoped via consent.sign (a guardian's finance.view is "their own money"; consent.sign keeps it
        // guardian-only, not school_admin/ops).
        path: '/my/payments',
        i18nKey: 'nav.myPayments',
        icon: <Wallet size={16} aria-hidden />,
        visible: (h) => h('consent.sign'),
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
    i18nKey: 'navGroup.administration',
    items: [
      {
        path: '/admin/approvals',
        i18nKey: 'nav.approvals',
        icon: <ClipboardCheck size={16} aria-hidden />,
        visible: (h) => h('operations.manage'),
      },
      {
        path: '/admin/withdrawals',
        i18nKey: 'nav.withdrawals',
        icon: <LogOut size={16} aria-hidden />,
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

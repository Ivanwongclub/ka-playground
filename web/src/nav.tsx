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

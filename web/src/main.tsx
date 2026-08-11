import { StrictMode, Suspense, lazy, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { App as AntApp, ConfigProvider } from 'antd';
// React 19 compatibility: antd v5 officially supports React 16–18; this patch restores
// the removed ReactDOM.render/unmount internals antd uses for Wave and static rendering
import '@ant-design/v5-patch-for-react-19';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
// Self-hosted fonts (§4) — no runtime CDN calls
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/montserrat/600.css';
import '@fontsource/montserrat/700.css';
import '@fontsource/noto-sans-hk/400.css';
import '@fontsource/noto-sans-hk/500.css';
import '@fontsource/noto-sans-sc/400.css';
import '@fontsource/noto-sans-sc/500.css';
import '@fontsource/jetbrains-mono/400.css';
import './i18n';
import { antdLocaleFor, htmlLangFor, storedLocale } from './i18n';
import type { KaLocale } from './i18n';
import { kaTheme } from './theme/theme';
import { AppShell } from './AppShell';
import { DemoGate } from './components/DemoGate';
import { Navigate, useLocation } from 'react-router-dom';
import { getToken } from './auth/session';
import { Placeholder } from './pages/Placeholder';
import { Dashboard } from './pages/Dashboard';
import { NotFound } from './pages/NotFound';
import './index.css';

// Route-level code-splitting (S01 step 7): heavy pages load on navigation.
// The charts library rides only the style-guide chunk.
// Development-only: DEV-gating the import (not just the route) so the Style Guide — and the
// charts library it carries — is dead-code-eliminated from production builds (S-UX1).
const StyleGuide = import.meta.env.DEV
  ? lazy(() => import('./pages/StyleGuide').then((m) => ({ default: m.StyleGuide })))
  : null;
// DEV-only DS2 component gallery — dead-code-eliminated from production, like the Style Guide (DS2 STEP 2).
const Ds2Gallery = import.meta.env.DEV
  ? lazy(() => import('./pages/Ds2Gallery').then((m) => ({ default: m.Ds2Gallery })))
  : null;
const Register = lazy(() => import('./pages/Register').then((m) => ({ default: m.Register })));
const PublicPay = lazy(() => import('./pages/PublicPay').then((m) => ({ default: m.PublicPay })));
const Activate = lazy(() => import('./pages/Activate').then((m) => ({ default: m.Activate })));
const AdminAudit = lazy(() => import('./pages/AdminAudit').then((m) => ({ default: m.AdminAudit })));
const Login = lazy(() => import('./pages/Login').then((m) => ({ default: m.Login })));
const AccessIdentity = lazy(() => import('./pages/AccessIdentity').then((m) => ({ default: m.AccessIdentity })));
const AdminProgrammes = lazy(() => import('./pages/AdminProgrammes').then((m) => ({ default: m.AdminProgrammes })));
const ConsentList = lazy(() => import('./pages/Consents').then((m) => ({ default: m.ConsentList })));
const ConsentSign = lazy(() => import('./pages/Consents').then((m) => ({ default: m.ConsentSign })));
const ConsentEvidence = lazy(() => import('./pages/ConsentEvidence').then((m) => ({ default: m.ConsentEvidence })));
const AdminConsentTemplates = lazy(() => import('./pages/AdminConsentTemplates').then((m) => ({ default: m.AdminConsentTemplates })));
const Enrolments = lazy(() => import('./pages/Enrolments').then((m) => ({ default: m.Enrolments })));
const EnrolmentPool = lazy(() => import('./pages/EnrolmentPool').then((m) => ({ default: m.EnrolmentPool })));
const FinancialIntegrity = lazy(() => import('./pages/FinancialIntegrity').then((m) => ({ default: m.FinancialIntegrity })));
const Approvals = lazy(() => import('./pages/Approvals').then((m) => ({ default: m.Approvals })));
const Withdrawals = lazy(() => import('./pages/Withdrawals').then((m) => ({ default: m.Withdrawals })));
const Payments = lazy(() => import('./pages/Payments').then((m) => ({ default: m.Payments })));
const Teams = lazy(() => import('./pages/Teams').then((m) => ({ default: m.Teams })));
const StudentTeam = lazy(() => import('./pages/StudentTeam').then((m) => ({ default: m.StudentTeam })));
const MySessions = lazy(() => import('./pages/SessionAttendance').then((m) => ({ default: m.MySessions })));
const ChildSessions = lazy(() => import('./pages/SessionAttendance').then((m) => ({ default: m.ChildSessions })));
const MentorAttendance = lazy(() => import('./pages/SessionAttendance').then((m) => ({ default: m.MentorAttendance })));
const OpsAttendance = lazy(() => import('./pages/SessionAttendance').then((m) => ({ default: m.OpsAttendance })));
const MyChildren = lazy(() => import('./pages/SelfService').then((m) => ({ default: m.MyChildren })));
const MyPayments = lazy(() => import('./pages/SelfService').then((m) => ({ default: m.MyPayments })));
const MyStudents = lazy(() => import('./pages/SelfService').then((m) => ({ default: m.MyStudents })));
const MyProfile = lazy(() => import('./pages/Profile360').then((m) => ({ default: m.MyProfile })));
const ChildProfile = lazy(() => import('./pages/Profile360').then((m) => ({ default: m.ChildProfile })));
const MemberEvents = lazy(() => import('./pages/Community').then((m) => ({ default: m.MemberEvents })));
const MemberDirectory = lazy(() => import('./pages/Community').then((m) => ({ default: m.MemberDirectory })));
const MemberProfile = lazy(() => import('./pages/Community').then((m) => ({ default: m.MemberProfile })));
const Refunds = lazy(() => import('./pages/Refunds').then((m) => ({ default: m.Refunds })));

function RequireAuth({ children }: { children: React.ReactElement }) {
  const location = useLocation();
  if (!getToken()) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }
  return children;
}

function Root() {
  const { i18n } = useTranslation();
  const [locale, setLocale] = useState<KaLocale>(storedLocale());

  useEffect(() => {
    document.documentElement.lang = htmlLangFor[locale];
    const onChange = (lng: string) => setLocale(lng as KaLocale);
    i18n.on('languageChanged', onChange);
    return () => i18n.off('languageChanged', onChange);
  }, [i18n, locale]);

  return (
    <ConfigProvider theme={kaTheme} locale={antdLocaleFor[locale]}>
      {/* §2 — App wrapper so message/notification/Modal inherit the theme */}
      <AntApp>
        <DemoGate>
        <BrowserRouter>
          <Suspense fallback={<div className="ka-route-loading" aria-hidden />}>
          <Routes>
            <Route path="/login" element={<Login />} />
            {/* S04C — public, unauthenticated surfaces (self-registration + the forwardable payment page) */}
            <Route path="/register" element={<Register />} />
            <Route path="/pay/:token" element={<PublicPay />} />
            <Route path="/activate/:token" element={<Activate />} />
            <Route element={<RequireAuth><AppShell /></RequireAuth>}>
              <Route index element={<Dashboard />} />
              {/* S-UX3 domain screens — routes retained as stubs; their nav items are hidden
                  until each S-UX3 card builds the screen and reveals its item (D-UX1.1). */}
              <Route path="/tracker" element={<Placeholder titleKey="empty.title" />} />
              {/* S-UX3-3a STEP 2 — the ops Team Formation view now occupies /team (nav item revealed). */}
              <Route path="/team" element={<Teams />} />
              {/* S-UX3-3b — the student team-formation surface (distinct from the ops /team). */}
              <Route path="/my/team" element={<StudentTeam />} />
              {/* S-UX3-4 — sessions / attendance surfaces (child-data reads gated in STEP 1). */}
              <Route path="/my/sessions" element={<MySessions />} />
              <Route path="/family/sessions" element={<ChildSessions />} />
              <Route path="/attendance" element={<MentorAttendance />} />
              <Route path="/admin/attendance" element={<OpsAttendance />} />
              {/* S-UX3-9 — guardian/teacher self-service (My Children / My Payments / My Students). */}
              <Route path="/my/children" element={<MyChildren />} />
              {/* R1-P360 — the student 360 (self) + the guardian child-view (same Profile360 per child). */}
              <Route path="/my/profile" element={<MyProfile />} />
              <Route path="/my/children/:studentId" element={<ChildProfile />} />
              <Route path="/my/payments" element={<MyPayments />} />
              <Route path="/my/students" element={<MyStudents />} />
              <Route path="/learn" element={<Placeholder titleKey="empty.title" />} />
              {/* S-UX3-8 — Member surfaces (events / directory / profile). */}
              <Route path="/events" element={<MemberEvents />} />
              <Route path="/directory" element={<MemberDirectory />} />
              <Route path="/profile" element={<MemberProfile />} />
              {/* Style Guide is a development-only design surface — never shipped in prod (S-UX1). */}
              {import.meta.env.DEV && StyleGuide && <Route path="/style-guide" element={<StyleGuide />} />}
              {import.meta.env.DEV && Ds2Gallery && <Route path="/ds2-gallery" element={<Ds2Gallery />} />}
              <Route path="/admin/audit" element={<AdminAudit />} />
              <Route path="/admin/access-identity" element={<AccessIdentity />} />
              <Route path="/admin/programmes" element={<AdminProgrammes />} />
              <Route path="/consents" element={<ConsentList />} />
              <Route path="/consents/:id" element={<ConsentSign />} />
              <Route path="/admin/consent-evidence" element={<ConsentEvidence />} />
              <Route path="/admin/consent-templates" element={<AdminConsentTemplates />} />
              <Route path="/enrolments" element={<Enrolments />} />
              <Route path="/admin/enrolment-pool" element={<EnrolmentPool />} />
              <Route path="/admin/financial-integrity" element={<FinancialIntegrity />} />
              <Route path="/admin/approvals" element={<Approvals />} />
              <Route path="/admin/withdrawals" element={<Withdrawals />} />
              <Route path="/admin/payments" element={<Payments />} />
              <Route path="/admin/refunds" element={<Refunds />} />
              {/* Catch-all: any unknown authed path (incl. /style-guide in prod) → 404 within the shell. */}
              <Route path="*" element={<NotFound />} />
            </Route>
          </Routes>
          </Suspense>
        </BrowserRouter>
        </DemoGate>
      </AntApp>
    </ConfigProvider>
  );
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Root />
  </StrictMode>,
);

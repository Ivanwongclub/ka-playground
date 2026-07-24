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
import { Navigate, useLocation } from 'react-router-dom';
import { getToken } from './auth/session';
import { Placeholder } from './pages/Placeholder';
import './index.css';

// Route-level code-splitting (S01 step 7): heavy pages load on navigation.
// The charts library rides only the style-guide chunk.
const StyleGuide = lazy(() => import('./pages/StyleGuide').then((m) => ({ default: m.StyleGuide })));
const AdminAudit = lazy(() => import('./pages/AdminAudit').then((m) => ({ default: m.AdminAudit })));
const Login = lazy(() => import('./pages/Login').then((m) => ({ default: m.Login })));
const AccessIdentity = lazy(() => import('./pages/AccessIdentity').then((m) => ({ default: m.AccessIdentity })));
const AdminProgrammes = lazy(() => import('./pages/AdminProgrammes').then((m) => ({ default: m.AdminProgrammes })));
const ConsentList = lazy(() => import('./pages/Consents').then((m) => ({ default: m.ConsentList })));
const ConsentSign = lazy(() => import('./pages/Consents').then((m) => ({ default: m.ConsentSign })));
const ConsentEvidence = lazy(() => import('./pages/ConsentEvidence').then((m) => ({ default: m.ConsentEvidence })));
const AdminConsentTemplates = lazy(() => import('./pages/AdminConsentTemplates').then((m) => ({ default: m.AdminConsentTemplates })));

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
        <BrowserRouter>
          <Suspense fallback={<div className="ka-route-loading" aria-hidden />}>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route element={<RequireAuth><AppShell /></RequireAuth>}>
              <Route index element={<Placeholder titleKey="empty.title" />} />
              <Route path="/tracker" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/team" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/learn" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/profile" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/style-guide" element={<StyleGuide />} />
              <Route path="/admin/audit" element={<AdminAudit />} />
              <Route path="/admin/access-identity" element={<AccessIdentity />} />
              <Route path="/admin/programmes" element={<AdminProgrammes />} />
              <Route path="/consents" element={<ConsentList />} />
              <Route path="/consents/:id" element={<ConsentSign />} />
              <Route path="/admin/consent-evidence" element={<ConsentEvidence />} />
              <Route path="/admin/consent-templates" element={<AdminConsentTemplates />} />
            </Route>
          </Routes>
          </Suspense>
        </BrowserRouter>
      </AntApp>
    </ConfigProvider>
  );
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Root />
  </StrictMode>,
);

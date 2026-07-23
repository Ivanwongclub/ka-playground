import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { App as AntApp, ConfigProvider } from 'antd';
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
import { StyleGuide } from './pages/StyleGuide';
import { Placeholder } from './pages/Placeholder';
import './index.css';

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
          <Routes>
            <Route element={<AppShell />}>
              <Route index element={<Placeholder titleKey="empty.title" />} />
              <Route path="/tracker" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/team" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/learn" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/profile" element={<Placeholder titleKey="empty.title" />} />
              <Route path="/style-guide" element={<StyleGuide />} />
            </Route>
          </Routes>
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

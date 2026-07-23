// Desktop shell: ProLayout per §6 (240px sider, dark chrome, logo slot).
// <768px: app shell per §17 — bottom tab bar + avatar/edge-swipe nav drawer, no hamburger.
import { useCallback, useEffect, useState } from 'react';
import { ProLayout } from '@ant-design/pro-components';
import { Avatar, Select } from 'antd';
import {
  GraduationCap,
  Languages,
  LayoutDashboard,
  Palette,
  Route as RouteIcon,
  ShieldCheck,
  User,
  Users,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { LOCALES, persistLocale } from './i18n';
import type { KaLocale } from './i18n';
import { kaColors } from './theme/theme';
import { BottomTabBar } from './components/mobile/BottomTabBar';
import { NavDrawer, useEdgeSwipe } from './components/mobile/NavDrawer';

function useIsMobile() {
  const [mobile, setMobile] = useState(() => window.matchMedia('(max-width: 767px)').matches);
  useEffect(() => {
    const mq = window.matchMedia('(max-width: 767px)');
    const onChange = (e: MediaQueryListEvent) => setMobile(e.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);
  return mobile;
}

export function AppShell() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const isMobile = useIsMobile();
  const [drawerOpen, setDrawerOpen] = useState(false);

  const openDrawer = useCallback(() => setDrawerOpen(true), []);
  useEdgeSwipe(openDrawer);

  const localeSwitcher = (
    <Select<KaLocale>
      size="small"
      value={i18n.language as KaLocale}
      aria-label={t('locale.label')}
      suffixIcon={<Languages size={14} color={kaColors.mutedForeground} aria-hidden />}
      onChange={(locale) => {
        void i18n.changeLanguage(locale);
        persistLocale(locale);
      }}
      options={LOCALES.map((l) => ({ value: l, label: t(`locale.${l}`) }))}
      style={{ minWidth: 120 }}
    />
  );

  const content = (
    <>
      <Outlet />
      <NavDrawer open={drawerOpen} onClose={() => setDrawerOpen(false)} />
    </>
  );

  if (isMobile) {
    return (
      <div className="ka-mobile-shell">
        <header className="ka-mobile-header">
          <button
            type="button"
            className="ka-mobile-avatar"
            aria-label={t('shell.openNavigation')}
            onClick={openDrawer}
          >
            <Avatar size={32} style={{ background: kaColors.card, color: kaColors.gold }}>
              KA
            </Avatar>
          </button>
          <span className="ka-mobile-title">{t('app.title')}</span>
          {localeSwitcher}
        </header>
        <main className="ka-mobile-content">{content}</main>
        <BottomTabBar />
      </div>
    );
  }

  return (
    <ProLayout
      layout="side"
      fixSiderbar
      siderWidth={240}
      title={t('app.title')}
      logo={<span className="ka-logo-mark">KA</span>}
      location={{ pathname }}
      route={{
        path: '/',
        routes: [
          { path: '/', name: t('nav.dashboard'), icon: <LayoutDashboard size={16} aria-hidden /> },
          { path: '/tracker', name: t('nav.tracker'), icon: <RouteIcon size={16} aria-hidden /> },
          { path: '/team', name: t('nav.team'), icon: <Users size={16} aria-hidden /> },
          { path: '/learn', name: t('nav.learn'), icon: <GraduationCap size={16} aria-hidden /> },
          { path: '/profile', name: t('nav.profile'), icon: <User size={16} aria-hidden /> },
          { path: '/style-guide', name: t('nav.styleGuide'), icon: <Palette size={16} aria-hidden /> },
          { path: '/admin/audit', name: t('nav.audit'), icon: <ShieldCheck size={16} aria-hidden /> },
        ],
      }}
      menuItemRender={(item, dom) => (
        <a
          onClick={(e) => {
            e.preventDefault();
            if (item.path) void navigate(item.path);
          }}
        >
          {dom}
        </a>
      )}
      actionsRender={() => [localeSwitcher]}
      avatarProps={{
        render: () => (
          <Avatar size={32} style={{ background: kaColors.card, color: kaColors.gold }}>
            KA
          </Avatar>
        ),
      }}
      contentStyle={{ padding: 24 }}
    >
      {content}
    </ProLayout>
  );
}

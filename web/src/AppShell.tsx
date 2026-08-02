// Desktop shell: ProLayout per §6 (240px sider, dark chrome, logo slot).
// <768px: app shell per §17 — bottom tab bar + avatar/edge-swipe nav drawer, no hamburger.
// S-UX1: role-aware grouped nav (visibleGroups), real logo, user menu + logout, breadcrumbs.
import { useCallback, useEffect, useState } from 'react';
import { ProLayout, type MenuDataItem } from '@ant-design/pro-components';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { asset } from './assets';
import { IdentityProvider, useIdentity } from './auth/identity';
import { visibleGroups, visibleLeaves } from './nav';
import { LocaleSwitcher } from './components/LocaleSwitcher';
import { UserMenu } from './components/UserMenu';
import { KaBreadcrumb } from './components/KaBreadcrumb';
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
  // Identity is fetched once here and shared with the whole authenticated tree
  // (nav, user menu, dashboards) so it survives child-route changes and refresh.
  return (
    <IdentityProvider>
      <ShellInner />
    </IdentityProvider>
  );
}

function ShellInner() {
  const { t } = useTranslation();
  const { has, loading } = useIdentity();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const isMobile = useIsMobile();
  const [drawerOpen, setDrawerOpen] = useState(false);

  const openDrawer = useCallback(() => setDrawerOpen(true), []);
  useEdgeSwipe(openDrawer);

  // Hold the chrome until we know who is signed in — never flash the wrong nav.
  if (loading) return <div className="ka-route-loading" aria-hidden />;

  const groups = visibleGroups(has);
  const leaves = visibleLeaves(has);

  const content = (
    <>
      {!isMobile && <KaBreadcrumb />}
      <Outlet />
      <NavDrawer open={drawerOpen} onClose={() => setDrawerOpen(false)} groups={groups} />
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
            <img src={asset('brand/armour-academy-logo.webp')} alt={t('shell.logoAlt')} style={{ height: 24 }} />
          </button>
          <span className="ka-mobile-title">{t('app.title')}</span>
          <LocaleSwitcher />
        </header>
        <main className="ka-mobile-content">{content}</main>
        <BottomTabBar leaves={leaves} />
      </div>
    );
  }

  // ProLayout route tree: Overview items sit at the top level; other groups render as
  // labelled sub-menus. Only leaf items (real path, no children) navigate.
  const routes: MenuDataItem[] = groups.flatMap<MenuDataItem>((g) =>
    g.i18nKey === 'navGroup.overview'
      ? g.items.map((it) => ({ path: it.path, name: t(it.i18nKey), icon: it.icon }))
      : [
          {
            path: g.i18nKey,
            name: t(g.i18nKey),
            routes: g.items.map((it) => ({ path: it.path, name: t(it.i18nKey), icon: it.icon })),
          },
        ],
  );

  return (
    <ProLayout
      layout="side"
      fixSiderbar
      siderWidth={240}
      title={t('app.title')}
      logo={<img src={asset('brand/armour-academy-logo.webp')} alt={t('shell.logoAlt')} style={{ height: 28 }} />}
      location={{ pathname }}
      route={{ path: '/', routes }}
      menu={{ defaultOpenAll: true }}
      menuItemRender={(item, dom) =>
        item.path && !item.routes ? (
          <a
            onClick={(e) => {
              e.preventDefault();
              void navigate(item.path!);
            }}
          >
            {dom}
          </a>
        ) : (
          dom
        )
      }
      actionsRender={() => [<LocaleSwitcher key="locale" />]}
      avatarProps={{ render: () => <UserMenu /> }}
      contentStyle={{ padding: 24 }}
    >
      {content}
    </ProLayout>
  );
}

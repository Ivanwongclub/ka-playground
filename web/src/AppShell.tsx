// Desktop shell: ProLayout per §6 (240px sider, dark chrome, logo slot).
// <768px: app shell per §17 — bottom tab bar + avatar/edge-swipe nav drawer, no hamburger.
// S-UX1: role-aware grouped nav (visibleGroups), real logo, user menu + logout, breadcrumbs.
import { useCallback, useEffect, useState } from 'react';
import { ProLayout, type MenuDataItem } from '@ant-design/pro-components';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { asset } from './assets';
import { IdentityProvider, useIdentity } from './auth/identity';
import { visibleGroups, visibleLeaves } from './nav';
import { LocaleSwitcher } from './components/LocaleSwitcher';
import { UserMenu } from './components/UserMenu';
import { NavFooter } from './components/NavFooter';
import { BottomTabBar } from './components/mobile/BottomTabBar';
import { NavDrawer, useEdgeSwipe } from './components/mobile/NavDrawer';
// C3-CHROME: the chrome ADOPTS the existing ElasticSearch DS2 primitive (§3.12) — its first consumer (built
// and used by nothing, audit S4 S-4). This makes AppShell an @/ds2 importer (added to the import-guard
// allowlist). ElasticSearch does NOT fetch; there is NO search endpoint (FLAG — no server change here), so it
// mounts INERT: onQuery is a no-op, groups stay [] → the primitive shows its real emptyMessage. Wire onQuery
// to a /search endpoint here when one lands — no change to the primitive.
import { ElasticSearch } from '@/ds2';

// C3-CHROME — the sider footer's build sha is STAFF-ONLY (audit S2 G-8). Family = student/guardian/member;
// staff = teacher/school_admin/academy_admin (ops/finance/audit/super are academy_admin + capability groups,
// OD-17). Default to family (no sha) for any unrecognised role — never leak the build sha to a non-staff view.
const STAFF_ROLES = ['teacher', 'school_admin', 'academy_admin'];

// The header typeahead — a thin controlled wrapper around ElasticSearch, mounted in the header content slot.
function HeaderSearch() {
  const { t } = useTranslation();
  const [value, setValue] = useState('');
  return (
    <div className="ka-hdr-search">
      <ElasticSearch
        value={value}
        onQuery={setValue}
        groups={[]}
        placeholder={t('chrome.searchPlaceholder')}
        emptyMessage={t('chrome.searchEmpty')}
      />
    </div>
  );
}

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
  const { has, identity, loading } = useIdentity();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const isMobile = useIsMobile();
  const [drawerOpen, setDrawerOpen] = useState(false);
  // P0-2b — desktop sider collapse (presentational chrome state; does NOT touch nav visibility/routing/identity).
  const [collapsed, setCollapsed] = useState(false);

  const openDrawer = useCallback(() => setDrawerOpen(true), []);
  useEdgeSwipe(openDrawer);

  // Hold the chrome until we know who is signed in — never flash the wrong nav.
  if (loading) return <div className="ka-route-loading" aria-hidden />;

  const groups = visibleGroups(has); // still the source for the mobile NavDrawer + gating (unchanged)
  const leaves = visibleLeaves(has); // the FLAT desktop sider + mobile tab bar
  const staff = STAFF_ROLES.includes(identity?.role ?? '');

  const content = (
    <>
      {/* C3-CHROME: the breadcrumb header is replaced by the platform header (brand · search · user chip). */}
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

  // C3-CHROME: FLAT sider — one item per visible leaf, NO group headers (the My Programme / Community /
  // Administration groups were a build invention; the prototype has none). Gating is untouched — the leaves
  // still come from visibleGroups→visibleLeaves, same predicates. The NavGroup data stays intact for the
  // mobile NavDrawer above; only the DESKTOP render flattens.
  const routes: MenuDataItem[] = leaves.map((it) => ({ path: it.path, name: t(it.i18nKey), icon: it.icon }));

  return (
    <ProLayout
      // C3-CHROME — layout is "mix" (NOT "side"): the prototype's chrome is a top header bar OVER a side nav,
      // and layout="side" renders NO top header at all (brand, nav, user chip and locale all stack inside the
      // sider — the search never appeared and the brand sat in the sider top, not a header cell). "mix" gives a
      // real top header spanning the width, with the flat nav still in the sider below.
      layout="mix"
      fixSiderbar
      fixedHeader
      siderWidth={240}
      location={{ pathname }}
      route={{ path: '/', routes }}
      menu={{ defaultOpenAll: true }}
      collapsed={collapsed}
      onCollapse={setCollapsed}
      // Brand lives in the HEADER (headerRender below), not the sider top — remove ProLayout's sider brand.
      menuHeaderRender={false}
      collapsedButtonRender={(isCollapsed) => (
        // ProLayout renders this content but does NOT wire the toggle in this version — wire it ourselves via
        // the controlled `collapsed` state (we own it). A real button element for keyboard/focus.
        <button
          type="button"
          className="ka-rail-collapse"
          aria-label={t(isCollapsed ? 'nav.expand' : 'nav.collapse')}
          onClick={() => setCollapsed(!isCollapsed)}
        >
          {isCollapsed ? <ChevronRight size={19} strokeWidth={1.9} aria-hidden /> : <ChevronLeft size={19} strokeWidth={1.9} aria-hidden />}
        </button>
      )}
      menuFooterRender={() => <NavFooter collapsed={collapsed} staff={staff} />}
      // C3-CHROME — HEADER via headerRender (the FALLBACK the ruling named). headerContentRender could not
      // produce the prototype chrome: layout="side" renders no header, so it never appeared. headerRender owns
      // the FULL header content — the brand CELL (width = sider width, own hairline right, collapse-aware), the
      // search (inert ElasticSearch — no endpoint, FLAG), a spacer, the locale switcher and the user chip. The
      // BELL is OMITTED (notifications unbuilt D6/B-19 — an inert bell is a dead affordance; ruling #1).
      headerRender={() => (
        <div className="ka-hdr">
          <div className="ka-hdr-brand" data-collapsed={collapsed || undefined}>
            <img src={asset('brand/armour-academy-logo.webp')} alt={t('shell.logoAlt')} style={{ height: 24, flex: 'none' }} />
            {!collapsed && <span className="ka-hdr-brand__word">{t('app.title')}</span>}
          </div>
          <HeaderSearch />
          <span className="ka-hdr__grow" />
          <LocaleSwitcher />
          <UserMenu />
        </div>
      )}
      menuItemRender={(item, dom) =>
        item.path && !item.routes ? (
          // P0-2b — aria-label makes the collapsed mini-rail readable (antd's hover tooltip is visual-only).
          <a
            aria-label={typeof item.name === 'string' ? item.name : undefined}
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
      contentStyle={{ padding: 24 }}
    >
      {content}
    </ProLayout>
  );
}

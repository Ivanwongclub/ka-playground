// Desktop shell: ProLayout per §6 (240px sider, dark chrome, logo slot).
// <768px: app shell per §17 — bottom tab bar + avatar/edge-swipe nav drawer, no hamburger.
// S-UX1: role-aware grouped nav (visibleGroups), real logo, user menu + logout, breadcrumbs.
import { useCallback, useEffect, useState } from 'react';
import { ProLayout, type MenuDataItem } from '@ant-design/pro-components';
import { ChevronLeft, ChevronRight, Search, Bell } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { asset } from './assets';
import { IdentityProvider, useIdentity } from './auth/identity';
import { useResource } from './api/useResource';
import { visibleGroups, visibleLeaves, isGuardianActor } from './nav';
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
const OPEN_CONSENT = new Set(['sent', 'viewed']); // consent-request states awaiting a signature (matches GuardianHome/Dashboard)

// The header typeahead — a thin controlled wrapper around ElasticSearch, with a leading magnifier (prototype
// .hdr-search: .hsico + input). The icon is a sibling (the DS2 primitive is not edited); the input is padded in
// CSS to clear it. Behaviour stays INERT — no /search endpoint (FLAG).
function HeaderSearch() {
  const { t } = useTranslation();
  const [value, setValue] = useState('');
  return (
    <div className="ka-hdr-search">
      <Search className="ka-hdr-search__ico" size={19} strokeWidth={1.9} aria-hidden />
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

  // C3-CHROME-FIX-2 — chrome badge counts. Fetched ONCE: ShellInner stays mounted across route changes (it wraps
  // the Outlet), and useResource keys on a CONSTANT url, so a navigation never refetches; the read fires once when
  // identity resolves (null → url) and then holds. Role-gated (null url = no fetch), from EXISTING entitled reads
  // only. Never blocks — the shell renders immediately and badges appear when data arrives. Counts DEGRADE
  // SILENTLY: a failed/absent read leaves no entry → no badge (never a 0, never an error). Zero also renders no
  // badge. Sources per role: /consents←consent-requests, /my/payments←orders, /admin/approvals←onboarding-queue,
  // /admin/withdrawals←withdrawal-requests. Student/teacher/member get none (no queue task; §3.13).
  const seesConsents = has('consent.view') && !has('events.rsvp');
  const isGuardian = isGuardianActor(has);
  const isOps = has('operations.manage');
  const consentRes = useResource<{ data: { status: string }[] }>(seesConsents ? '/api/consent-requests' : null);
  const orderRes = useResource<{ data: { status: string; payer_party: string }[] }>(isGuardian ? '/api/orders' : null);
  const queueRes = useResource<{ accounts?: unknown[]; links?: unknown[] }>(isOps ? '/api/admin/onboarding-queue' : null);
  const wdRes = useResource<{ data: { status: string }[] }>(isOps ? '/api/withdrawal-requests' : null);
  const navCounts: Record<string, number> = {};
  if (seesConsents && consentRes.data && !consentRes.error) navCounts['/consents'] = consentRes.data.data.filter((c) => OPEN_CONSENT.has(c.status)).length;
  if (isGuardian && orderRes.data && !orderRes.error) navCounts['/my/payments'] = orderRes.data.data.filter((o) => o.status === 'issued' && (o.payer_party === 'guardian' || o.payer_party === 'student')).length;
  if (isOps && queueRes.data && !queueRes.error) navCounts['/admin/approvals'] = (queueRes.data.accounts?.length ?? 0) + (queueRes.data.links?.length ?? 0);
  if (isOps && wdRes.data && !wdRes.error) navCounts['/admin/withdrawals'] = wdRes.data.data.filter((w) => w.status === 'pending').length;
  const bellCount = Object.values(navCounts).reduce((a, b) => a + b, 0);

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
      // C3-CHROME: prototype sider measurements — 250px expanded (siderWidth), 58px collapsed. ProLayout
      // HARDCODES the collapsed width to 64 (SiderMenu.js, not a prop), so the 58px collapse is forced in CSS
      // (index.css, .ant-layout-sider-collapsed override). The brand cell in headerRender mirrors both widths.
      siderWidth={250}
      location={{ pathname }}
      route={{ path: '/', routes }}
      menu={{ defaultOpenAll: true }}
      collapsed={collapsed}
      onCollapse={setCollapsed}
      // Brand lives in the HEADER (headerRender below), not the sider top — remove ProLayout's sider brand.
      menuHeaderRender={false}
      collapsedButtonRender={(isCollapsed) => (
        // C3-CHROME-FIX-2 — prototype .scol: a 36px circle floating on the sider's RIGHT EDGE (position:fixed at
        // left = sider-width − 18px, so half-on the edge), chevron 17px stroke 2.2, --card bg + shadow, and a
        // hover tooltip via ::after (data-tip). We own the toggle via the controlled `collapsed` state. The left
        // offset flips with the sider width (250→58) inline; CSS transitions it. A real button for keyboard/focus.
        <button
          type="button"
          className="ka-rail-collapse"
          data-tip={t('chrome.menuTip')}
          style={{ left: isCollapsed ? 40 : 232 }}
          aria-label={t(isCollapsed ? 'nav.expand' : 'nav.collapse')}
          onClick={() => setCollapsed(!isCollapsed)}
        >
          {isCollapsed ? <ChevronRight size={17} strokeWidth={2.2} aria-hidden /> : <ChevronLeft size={17} strokeWidth={2.2} aria-hidden />}
        </button>
      )}
      menuFooterRender={() => <NavFooter collapsed={collapsed} staff={staff} />}
      // C3-CHROME — HEADER via headerRender (the ruling's fallback): brand CELL (= sider width, hairline right,
      // collapse-aware) · search (magnifier + inert ElasticSearch, FLAG) · spacer · BELL + badge · hdr-sep · user
      // chip. The bell's badge is the role's TOTAL pending count from existing reads; the DRAWER is deferred
      // (D6/B-19) — clicking opens nothing yet (FLAG). The language switcher moved INTO the user menu (an account
      // preference, not chrome — C3-CHROME-FIX-2 item 6).
      headerRender={() => (
        <div className="ka-hdr">
          <div className="ka-hdr-brand" data-collapsed={collapsed || undefined}>
            <img src={asset('brand/armour-academy-logo.webp')} alt={t('shell.logoAlt')} style={{ height: 24, flex: 'none' }} />
            {!collapsed && <span className="ka-hdr-brand__word">{t('app.title')}</span>}
          </div>
          <HeaderSearch />
          <span className="ka-hdr__grow" />
          {/* Bell: real badge from existing reads; drawer DEFERRED (D6/B-19) — no onClick target yet (FLAG). No
              badge at zero, and no badge when the count read failed (silent degradation). */}
          <button type="button" className="ka-hico" aria-label={t('chrome.notifications')}>
            <Bell size={19} strokeWidth={1.9} aria-hidden />
            {bellCount > 0 && <span className="ka-badge" aria-label={t('chrome.pending', { count: bellCount })}>{bellCount}</span>}
          </button>
          <span className="ka-hdr-sep" aria-hidden />
          <UserMenu />
        </div>
      )}
      menuItemRender={(item, dom) => {
        // C3-CHROME-FIX-2 — .nbadge: a queue count on the item (Kit §3.13, queue items only). Present iff the
        // role's existing read returned a positive count; margin-left:auto pill expanded, an 8px dot collapsed.
        const n = item.path ? navCounts[item.path] : undefined;
        const badge = n && n > 0 ? <span className="ka-nbadge" aria-label={t('chrome.pending', { count: n })}>{n}</span> : null;
        return item.path && !item.routes ? (
          // P0-2b — aria-label makes the collapsed mini-rail readable (antd's hover tooltip is visual-only).
          <a
            className="ka-navitem"
            aria-label={typeof item.name === 'string' ? item.name : undefined}
            onClick={(e) => {
              e.preventDefault();
              void navigate(item.path!);
            }}
          >
            {dom}{badge}
          </a>
        ) : (
          dom
        );
      }
      }
      contentStyle={{ padding: 24 }}
    >
      {content}
    </ProLayout>
  );
}

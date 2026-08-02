// §17.2 — fixed bottom tab bar (56px + safe-area). Active = gold icon + label;
// inactive = muted icon only. S-UX1: role-aware — renders the caller's first few
// visible nav leaves (from AppShell); the rest live in the drawer.
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';
import { kaColors } from '../../theme/theme';
import type { NavLeaf } from '../../nav';

export function BottomTabBar({ leaves }: { leaves: NavLeaf[] }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const tabs = leaves.slice(0, 5); // 56px bar holds up to five; overflow lives in the drawer

  return (
    <nav className="ka-tabbar" aria-label={t('nav.dashboard')}>
      {tabs.map(({ path, i18nKey, icon }) => {
        const active = pathname === path;
        return (
          <button
            key={path}
            type="button"
            className="ka-tabbar-item"
            aria-current={active ? 'page' : undefined}
            onClick={() => void navigate(path)}
            style={{ color: active ? kaColors.gold : kaColors.mutedForeground }}
          >
            {icon}
            {active && <span className="ka-tabbar-label">{t(i18nKey)}</span>}
          </button>
        );
      })}
    </nav>
  );
}

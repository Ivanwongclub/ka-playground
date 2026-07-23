// §17.2 — fixed bottom tab bar, 5 items, 56px + safe-area. Active = gold icon + label;
// inactive = muted icon only. Role-specific sets arrive with auth in S01; the shell
// renders the student set as the scaffold default.
import { LayoutDashboard, Route, Users, GraduationCap, User } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';
import { kaColors } from '../../theme/theme';

const TABS = [
  { key: '/', i18nKey: 'nav.dashboard', Icon: LayoutDashboard },
  { key: '/tracker', i18nKey: 'nav.tracker', Icon: Route },
  { key: '/team', i18nKey: 'nav.team', Icon: Users },
  { key: '/learn', i18nKey: 'nav.learn', Icon: GraduationCap },
  { key: '/profile', i18nKey: 'nav.profile', Icon: User },
] as const;

export function BottomTabBar() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { pathname } = useLocation();

  return (
    <nav className="ka-tabbar" aria-label={t('nav.dashboard')}>
      {TABS.map(({ key, i18nKey, Icon }) => {
        const active = pathname === key;
        return (
          <button
            key={key}
            type="button"
            className="ka-tabbar-item"
            aria-current={active ? 'page' : undefined}
            onClick={() => void navigate(key)}
          >
            <Icon size={20} color={active ? kaColors.gold : kaColors.mutedForeground} aria-hidden />
            {active && <span className="ka-tabbar-label">{t(i18nKey)}</span>}
          </button>
        );
      })}
    </nav>
  );
}

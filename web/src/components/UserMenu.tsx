// C3-CHROME — the header user chip: avatar + name + role + caret → a click-menu. The menu carries the LANGUAGE
// switcher (moved out of the header — an account preference, not chrome; C3-CHROME-FIX-2 item 6) above the
// divider, then Log out (danger-coloured). Profile / Settings are OMITTED — no route target yet, and a "not
// available" toast is instructional copy on a dead affordance (ruling); they return with the Profile screen
// (S-UX3). The language capability is not dropped, only relocated. Desktop chrome only.
import { Avatar, Dropdown, Typography } from 'antd';
import { Check, ChevronDown, LogOut } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { kaColors } from '../theme/theme';
import { useIdentity } from '../auth/identity';
import { logout } from '../auth/session';
import { LOCALES, persistLocale } from '../i18n';
import type { KaLocale } from '../i18n';

function initialsOf(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .map((part) => part[0])
      .slice(0, 2)
      .join('')
      .toUpperCase() || 'KA'
  );
}

export function UserMenu() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { identity } = useIdentity();
  const name = identity?.name ?? t('app.academy');
  const roleLabel = identity ? t(`role.${identity.role}`) : '';
  const current = i18n.language as KaLocale;

  return (
    <Dropdown
      trigger={['click']}
      placement="bottomRight"
      menu={{
        // Language switcher (checkmark on the active locale) → divider → Log out (danger). Profile/Settings omitted.
        items: [
          ...LOCALES.map((l) => ({
            key: `lang:${l}`,
            icon: l === current ? <Check size={16} aria-hidden /> : <span style={{ display: 'inline-block', width: 16 }} />,
            label: t(`locale.${l}`),
          })),
          { type: 'divider' as const },
          { key: 'logout', danger: true, icon: <LogOut size={19} strokeWidth={1.9} aria-hidden />, label: t('nav.signOut') },
        ],
        onClick: ({ key }) => {
          if (key.startsWith('lang:')) {
            const l = key.slice(5) as KaLocale;
            void i18n.changeLanguage(l);
            persistLocale(l);
          } else if (key === 'logout') {
            void logout().finally(() => navigate('/login'));
          }
        },
      }}
    >
      <button type="button" className="ka-userchip" aria-label={t('userMenu.label')}>
        <Avatar size={32} style={{ background: kaColors.card, color: kaColors.gold, flex: 'none' }}>
          {initialsOf(name)}
        </Avatar>
        <span className="ka-userchip__id">
          <Typography.Text strong ellipsis className="ka-userchip__name">{name}</Typography.Text>
          <Typography.Text type="secondary" ellipsis className="ka-userchip__role">{roleLabel}</Typography.Text>
        </span>
        <ChevronDown size={14} strokeWidth={1.9} aria-hidden className="ka-userchip__caret" />
      </button>
    </Dropdown>
  );
}

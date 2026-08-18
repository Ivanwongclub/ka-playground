// C3-CHROME — the header user chip: avatar + name + role + caret → a click-menu (prototype .userchip +
// .usermenu). The chip surfaces who is signed in; the menu carries Log out (existing sign-out). Profile /
// Settings are OMITTED — no route target yet, and a disabled item is the same dead affordance as the bell
// (C3 ruling #3). Desktop chrome only (the mobile shell has its own header).
import { Avatar, Dropdown, Typography } from 'antd';
import { ChevronDown, LogOut } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { kaColors } from '../theme/theme';
import { useIdentity } from '../auth/identity';
import { logout } from '../auth/session';

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
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { identity } = useIdentity();
  const name = identity?.name ?? t('app.academy');
  const roleLabel = identity ? t(`role.${identity.role}`) : '';

  return (
    <Dropdown
      trigger={['click']}
      placement="bottomRight"
      menu={{
        // Log out only — Profile/Settings omitted (C3 ruling #3). Name + role live in the chip, not the menu.
        items: [{ key: 'logout', icon: <LogOut size={19} strokeWidth={1.9} aria-hidden />, label: t('nav.signOut') }],
        onClick: ({ key }) => {
          if (key === 'logout') void logout().finally(() => navigate('/login'));
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

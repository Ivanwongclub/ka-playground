// S-UX1 — header user menu: who is signed in (name + role) and Logout. Replaces the
// static non-interactive avatar. Profile SCREEN is not built yet (S-UX3), so identity
// is surfaced inline here rather than linking to an empty stub.
import { Avatar, Dropdown, Typography } from 'antd';
import { LogOut } from 'lucide-react';
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
        items: [
          {
            key: 'whoami',
            disabled: true,
            label: (
              <div style={{ padding: '4px 0', lineHeight: 1.3 }}>
                <Typography.Text strong style={{ display: 'block' }}>
                  {name}
                </Typography.Text>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  {roleLabel}
                </Typography.Text>
              </div>
            ),
          },
          { type: 'divider' },
          { key: 'logout', icon: <LogOut size={16} aria-hidden />, label: t('nav.signOut') },
        ],
        onClick: ({ key }) => {
          if (key === 'logout') void logout().finally(() => navigate('/login'));
        },
      }}
    >
      <button
        type="button"
        aria-label={t('userMenu.label')}
        style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
      >
        <Avatar size={32} style={{ background: kaColors.card, color: kaColors.gold }}>
          {initialsOf(name)}
        </Avatar>
      </button>
    </Dropdown>
  );
}

// §17.2 — navigation drawer (left, 85% width, scrim). Opened by avatar tap or
// left-edge swipe-in; closed by scrim tap, left swipe or back. Never a hamburger.
// S-UX1: role-aware — renders the caller's visible nav groups + a real user card
// (name/role) and Logout. Stub items (notifications/settings/help/style-guide) removed.
import { useEffect } from 'react';
import { Avatar, Drawer, Menu } from 'antd';
import { LogOut } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { kaColors } from '../../theme/theme';
import { useIdentity } from '../../auth/identity';
import { logout } from '../../auth/session';
import type { NavGroup } from '../../nav';

interface NavDrawerProps {
  open: boolean;
  onClose: () => void;
  groups: NavGroup[];
}

export function NavDrawer({ open, onClose, groups }: NavDrawerProps) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { identity } = useIdentity();

  // §17.6 — browser back closes the topmost layer first
  useEffect(() => {
    if (!open) return;
    window.history.pushState({ kaDrawer: true }, '');
    const onPop = () => onClose();
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [open, onClose]);

  const name = identity?.name ?? t('app.academy');
  const roleLabel = identity ? t(`role.${identity.role}`) : t('app.title');

  const items = [
    ...groups.map((g) => ({
      key: g.i18nKey,
      type: 'group' as const,
      label: t(g.i18nKey),
      children: g.items.map((it) => ({ key: it.path, icon: it.icon, label: t(it.i18nKey) })),
    })),
    { type: 'divider' as const },
    { key: 'sign-out', icon: <LogOut size={16} aria-hidden />, label: t('nav.signOut') },
  ];

  return (
    <Drawer
      placement="left"
      width="85%"
      open={open}
      onClose={onClose}
      closable={false}
      styles={{ body: { padding: 0, background: kaColors.background } }}
    >
      <div className="ka-drawer-usercard">
        <Avatar size={40} style={{ background: kaColors.card, color: kaColors.gold }}>
          {name.split(/\s+/).filter(Boolean).map((p) => p[0]).slice(0, 2).join('').toUpperCase() || 'KA'}
        </Avatar>
        <div>
          <div className="ka-drawer-username">{name}</div>
          <div className="ka-drawer-usermeta">{roleLabel}</div>
        </div>
      </div>
      <Menu
        mode="inline"
        theme="dark"
        selectable={false}
        style={{ background: 'transparent', border: 'none' }}
        onClick={({ key }) => {
          if (key === 'sign-out') {
            void logout().finally(() => navigate('/login'));
          } else {
            void navigate(key);
          }
          onClose();
        }}
        items={items}
      />
    </Drawer>
  );
}

// §17.4 — left-edge swipe-in opens the drawer. Attach once in the shell.
export function useEdgeSwipe(onOpen: () => void) {
  useEffect(() => {
    let startX = -1;
    let startY = -1;
    const onStart = (e: TouchEvent) => {
      const touch = e.touches[0];
      if (touch.clientX <= 20) {
        startX = touch.clientX;
        startY = touch.clientY;
      }
    };
    const onMove = (e: TouchEvent) => {
      if (startX < 0) return;
      const touch = e.touches[0];
      const dx = touch.clientX - startX;
      const dy = Math.abs(touch.clientY - startY);
      if (dx > 48 && dy < 32) {
        startX = -1;
        onOpen();
      }
    };
    const onEnd = () => {
      startX = -1;
    };
    window.addEventListener('touchstart', onStart, { passive: true });
    window.addEventListener('touchmove', onMove, { passive: true });
    window.addEventListener('touchend', onEnd, { passive: true });
    return () => {
      window.removeEventListener('touchstart', onStart);
      window.removeEventListener('touchmove', onMove);
      window.removeEventListener('touchend', onEnd);
    };
  }, [onOpen]);
}

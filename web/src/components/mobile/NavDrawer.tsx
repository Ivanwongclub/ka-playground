// §17.2 — navigation drawer (left, 85% width, scrim). Opened by avatar tap or
// left-edge swipe-in; closed by scrim tap, left swipe or back. Never a hamburger.
import { useEffect } from 'react';
import { Avatar, Drawer, Menu } from 'antd';
import { Bell, CircleHelp, LogOut, Palette, Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { kaColors } from '../../theme/theme';

interface NavDrawerProps {
  open: boolean;
  onClose: () => void;
}

export function NavDrawer({ open, onClose }: NavDrawerProps) {
  const { t } = useTranslation();
  const navigate = useNavigate();

  // §17.6 — browser back closes the topmost layer first
  useEffect(() => {
    if (!open) return;
    window.history.pushState({ kaDrawer: true }, '');
    const onPop = () => onClose();
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [open, onClose]);

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
          KA
        </Avatar>
        <div>
          <div className="ka-drawer-username">{t('app.academy')}</div>
          <div className="ka-drawer-usermeta">{t('app.title')}</div>
        </div>
      </div>
      <Menu
        mode="inline"
        theme="dark"
        selectable={false}
        style={{ background: 'transparent', border: 'none' }}
        onClick={({ key }) => {
          if (key === 'style-guide') void navigate('/style-guide');
          onClose();
        }}
        items={[
          { key: 'notifications', icon: <Bell size={16} aria-hidden />, label: t('nav.notifications') },
          { key: 'style-guide', icon: <Palette size={16} aria-hidden />, label: t('nav.styleGuide') },
          { key: 'settings', icon: <Settings size={16} aria-hidden />, label: t('nav.settings') },
          { key: 'help', icon: <CircleHelp size={16} aria-hidden />, label: t('nav.help') },
          { type: 'divider' },
          { key: 'sign-out', icon: <LogOut size={16} aria-hidden />, label: t('nav.signOut') },
        ]}
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

// P0-2b — the sider-bottom footer scaffold (desktop; the mobile shell has no footer area). Renders ONLY the
// pieces that need no new data: an env dot (import.meta.env), the build-time version (__APP_VERSION__ git sha),
// and a logout icon (logout() already exists). The session-timer and last-sign-in slots are DEFERRED — they
// render ABSENT (entitlement-iff), lighting up only when their Identity/auth data card lands. No faked data.
// Presentational chrome only: no nav logic, no visibleGroups/routing/identity change.
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { LogOut } from 'lucide-react';
import { logout } from '../auth/session';

export function NavFooter({ collapsed = false }: { collapsed?: boolean }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const dev = import.meta.env.DEV;
  return (
    <div className={`ka-navfooter${collapsed ? ' ka-navfooter--mini' : ''}`}>
      {/* env dot — colour from DEV/PROD; the technical env name is the title/aria-label (not translatable copy) */}
      <span
        className={`ka-navfooter__envdot ka-navfooter__envdot--${dev ? 'dev' : 'prod'}`}
        title={import.meta.env.MODE}
        aria-label={import.meta.env.MODE}
      />
      {/* version — build-time git short-sha (expression, not a translatable literal) */}
      {!collapsed && <span className="ka-navfooter__version">{__APP_VERSION__}</span>}
      {/* DEFERRED slots (session timer · last sign-in) — render nothing until their data card lands. No "0:00". */}
      <span className="ka-navfooter__spacer" />
      <button
        type="button"
        className="ka-navfooter__logout"
        aria-label={t('nav.signOut')}
        onClick={() => void logout().finally(() => navigate('/login'))}
      >
        <LogOut size={18} strokeWidth={1.9} aria-hidden />
      </button>
    </div>
  );
}

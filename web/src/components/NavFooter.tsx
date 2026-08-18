// C3-CHROME — the sider-bottom footer, ROLE-SHAPED (audit S2 G-8: a build sha on a child's phone is wrong).
//   STAFF  (teacher · school_admin · academy_admin): env dot + build-time version (__APP_VERSION__ git sha) + sign-out.
//   FAMILY (student · guardian · member):            sign-out ONLY.
// The prototype's "Last sign-in <date>" and session-timer slots are OMITTED on both — /me carries no
// last-sign-in and there is no session issue/expiry to count against (C3 ruling #2, FLAGGED for the server
// field). No faked "0:00", no faked date. Presentational chrome only: no nav logic, no gating/identity change.
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { LogOut } from 'lucide-react';
import { logout } from '../auth/session';

export function NavFooter({ collapsed = false, staff = false }: { collapsed?: boolean; staff?: boolean }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const dev = import.meta.env.DEV;
  return (
    <div className={`ka-navfooter${collapsed ? ' ka-navfooter--mini' : ''}`}>
      {staff && (
        <>
          {/* env dot — colour from DEV/PROD; the technical env name is the title/aria-label (not translatable copy) */}
          <span
            className={`ka-navfooter__envdot ka-navfooter__envdot--${dev ? 'dev' : 'prod'}`}
            title={import.meta.env.MODE}
            aria-label={import.meta.env.MODE}
          />
          {/* version — build-time git short-sha (expression, not a translatable literal). STAFF ONLY. */}
          {!collapsed && <span className="ka-navfooter__version">{__APP_VERSION__}</span>}
        </>
      )}
      {/* FLAGGED-ABSENT slots (last sign-in · session timer) — render nothing until the server carries them (ruling #2). */}
      <span className="ka-navfooter__spacer" />
      <button
        type="button"
        className="ka-navfooter__logout"
        aria-label={t('nav.signOut')}
        onClick={() => void logout().finally(() => navigate('/login'))}
      >
        <LogOut size={19} strokeWidth={1.9} aria-hidden />
      </button>
    </div>
  );
}

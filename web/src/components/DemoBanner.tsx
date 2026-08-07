import { useTranslation } from 'react-i18next';

/**
 * Persistent, unobtrusive "this is a synthetic-data demo" strip. Shown on EVERY page
 * (mounted above the router by DemoGate) whenever the server reports demo mode is on.
 * Trilingual via i18n. Marking only — it carries no behaviour.
 */
export function DemoBanner() {
  const { t } = useTranslation();
  return (
    <div className="ka-demo-banner" role="note" aria-label={t('demo.banner')}>
      <span className="ka-demo-banner__dot" aria-hidden />
      {t('demo.banner')}
    </div>
  );
}

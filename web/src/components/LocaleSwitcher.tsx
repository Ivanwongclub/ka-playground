// S-UX1 — the locale switcher extracted from the shell so PUBLIC pages (login,
// register, activate, pay) can switch language too — they render outside AppShell.
import { Select } from 'antd';
import { Languages } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { LOCALES, persistLocale } from '../i18n';
import type { KaLocale } from '../i18n';
import { kaColors } from '../theme/theme';

export function LocaleSwitcher({ style }: { style?: React.CSSProperties }) {
  const { t, i18n } = useTranslation();
  return (
    <Select<KaLocale>
      size="small"
      value={i18n.language as KaLocale}
      aria-label={t('locale.label')}
      suffixIcon={<Languages size={14} color={kaColors.mutedForeground} aria-hidden />}
      onChange={(locale) => {
        void i18n.changeLanguage(locale);
        persistLocale(locale);
      }}
      options={LOCALES.map((l) => ({ value: l, label: t(`locale.${l}`) }))}
      style={{ minWidth: 120, ...style }}
    />
  );
}

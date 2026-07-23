// §12.1 empty-state pattern for routes whose modules arrive in later sprints:
// icon 32px muted → H3 → one caption line. No illustrations, no emoji.
import { Inbox } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { kaColors } from '../theme/theme';

export function Placeholder({ titleKey }: { titleKey: string }) {
  const { t } = useTranslation();
  return (
    <div className="ka-empty">
      <Inbox size={32} color={kaColors.mutedForeground} aria-hidden />
      <h3>{t(titleKey)}</h3>
      <p>{t('empty.caption')}</p>
    </div>
  );
}

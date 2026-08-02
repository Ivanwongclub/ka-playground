// S-UX1 — breadcrumb trail derived from the current route via the nav config, so labels
// stay i18n and in sync with the menu. Academy › [group] › [page].
import { Breadcrumb } from 'antd';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router-dom';
import { NAV } from '../nav';

export function KaBreadcrumb() {
  const { t } = useTranslation();
  const { pathname } = useLocation();

  // Longest-prefix match so detail pages (e.g. /consents/:id) resolve to their list item.
  const leaves = NAV.flatMap((g) => g.items.map((it) => ({ ...it, groupKey: g.i18nKey })));
  const match =
    leaves
      .filter((l) => l.path !== '/' && pathname.startsWith(l.path))
      .sort((a, b) => b.path.length - a.path.length)[0] ??
    leaves.find((l) => l.path === '/')!;

  const items: { title: string }[] = [{ title: t('app.academy') }];
  if (match.groupKey !== 'navGroup.overview') items.push({ title: t(match.groupKey) });
  items.push({ title: t(match.i18nKey) });

  return <Breadcrumb items={items} style={{ marginBottom: 16 }} />;
}

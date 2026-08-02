// S-UX2a — the ONE money formatter. Every monetary value is stored as integer minor
// units carrying an ISO currency code (never a float). Display divides by 100 and lets
// Intl currency style apply the symbol and the currency's own fraction digits — never a
// hardcoded '$' or 'en-HK'. This is the generalised PublicPay.tsx pattern.
export function formatMoney(
  minor: number | null | undefined,
  currency: string,
  locale: string,
): string {
  if (minor === null || minor === undefined || Number.isNaN(minor)) return '—';
  return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(minor / 100);
}

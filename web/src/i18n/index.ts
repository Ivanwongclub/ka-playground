// i18n scaffold — OD-19. Three locales, no user-facing string outside these files.
// Missing keys warn loudly in dev and fail the i18n:check script.
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import type { Locale as AntdLocale } from 'antd/es/locale';
import enUS from 'antd/es/locale/en_US';
import zhTW from 'antd/es/locale/zh_TW';
import zhCN from 'antd/es/locale/zh_CN';
import en from './locales/en.json';
import zhTC from './locales/zh-TC.json';
import zhSC from './locales/zh-SC.json';

export const LOCALES = ['en', 'zh-TC', 'zh-SC'] as const;
export type KaLocale = (typeof LOCALES)[number];

export const antdLocaleFor: Record<KaLocale, AntdLocale> = {
  en: enUS,
  'zh-TC': zhTW,
  'zh-SC': zhCN,
};

export const htmlLangFor: Record<KaLocale, string> = {
  en: 'en',
  'zh-TC': 'zh-Hant-HK',
  'zh-SC': 'zh-Hans',
};

const STORAGE_KEY = 'ka.locale';

export function storedLocale(): KaLocale {
  const v = localStorage.getItem(STORAGE_KEY);
  return (LOCALES as readonly string[]).includes(v ?? '') ? (v as KaLocale) : 'en';
}

export function persistLocale(locale: KaLocale): void {
  localStorage.setItem(STORAGE_KEY, locale);
  document.documentElement.lang = htmlLangFor[locale];
}

void i18n.use(initReactI18next).init({
  resources: {
    en: { translation: en },
    'zh-TC': { translation: zhTC },
    'zh-SC': { translation: zhSC },
  },
  lng: storedLocale(),
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  saveMissing: true,
  missingKeyHandler: (lngs, _ns, key) => {
    // A missing key is a defect (OD-19) — surface it, never silently fall through.
    console.warn(`[i18n] MISSING KEY "${key}" for ${lngs.join(', ')}`);
  },
});

export default i18n;

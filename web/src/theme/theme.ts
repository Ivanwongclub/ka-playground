// KA theme — Design System v2.1 §5. The only theme: darkAlgorithm with KA seeds.
// No light mode, no theme toggle (client decision 23 Jul 2026).
import { theme as antdTheme } from 'antd';
import type { ThemeConfig } from 'antd';

// DS2 v3 palette (supersedes v2, owner-approved). theme.ts remains the single source; tokens.css +
// index.css :root mirror it, gated by scripts/ds2-tokens-check.mjs.
export const kaColors = {
  background: '#0F0D14',
  card: '#191521',
  foreground: '#FFFFFF',          // headings / high-emphasis text
  foregroundSoft: '#D6D9E0',      // v3: body text — softer than pure white (colorText)
  gold: '#E0A83B',
  goldHover: '#EAB652',
  muted: '#1E1927',
  mutedForeground: '#9BA1AC',
  border: '#26232E', // decorative separators: dividers, card edges, table lines (1.4.11-exempt)
  borderStrong: '#726889', // control boundaries: inputs, selects, buttons — 3.48:1 on card, 3.76:1 on bg
  success: '#4FB477',
  warning: '#E8863C',
  danger: '#E5646E',
  pending: '#4D7CF0',             // v3: informational / in-progress (colorInfo — no longer gold)
} as const;

// Category accents (§3.3) — programme colour coding
export const kaCategoryAccents = {
  language: '#6366F1',
  stem: '#A855F7',
  arts: '#EC4899',
  maths: '#F97316',
  featured: '#06B6D4',
} as const;

const shared = {
  borderRadius: 10, // brief rounded-lg; md=8 stays component-level (no antd token slot)
  borderRadiusSM: 6,
  fontFamily: "Inter, 'Noto Sans HK', 'Noto Sans SC', system-ui, sans-serif",
  fontSize: 14,
  // §4 type scale — H1/H2/H3 32/24/18; without these antd derives 38/30/24
  fontSizeHeading1: 32,
  fontSizeHeading2: 24,
  fontSizeHeading3: 18,
};

export const kaTheme: ThemeConfig = {
  cssVar: true,
  hashed: false,
  algorithm: antdTheme.darkAlgorithm,
  token: {
    ...shared,
    colorPrimary: kaColors.gold, // gold leads in dark (unchanged)
    colorInfo: kaColors.pending, // v3: informational is blue-pending, no longer gold
    colorError: kaColors.danger,
    colorSuccess: kaColors.success,
    colorWarning: kaColors.warning,
    colorBgLayout: kaColors.background,
    colorBgContainer: kaColors.card,
    // v3 text ramp: headings pure white, body the softer foregroundSoft, secondary the muted tone
    colorText: kaColors.foregroundSoft,
    colorTextHeading: kaColors.foreground,
    colorTextSecondary: kaColors.mutedForeground, // §3.1 mapping — one source of truth
    // 1.4.11 split (design review, 23 Jul 2026): controls get the 3.0-compliant
    // boundary; decorative separators keep the quiet aubergine line
    colorBorder: kaColors.borderStrong,
    colorBorderSecondary: kaColors.border,
    controlOutline: kaColors.gold, // solid — 8.01:1 on card; 0.35 alpha blended to 2.06
    fontFamilyCode: "'JetBrains Mono', monospace",
  },
  components: {
    // P0-2 glass chrome: sider/header fill goes transparent so the ambient ground shows through and the
    // backdrop-filter (CSS-only, index.css §glass) has something to blur. colorBgLayout stays intact.
    Layout: { siderBg: 'transparent', headerBg: 'transparent' },
    Menu: { darkItemSelectedColor: kaColors.gold, darkItemHoverBg: kaColors.muted },
    Button: { primaryColor: kaColors.background }, // dark text on gold
    Badge: { colorError: kaColors.warning }, // v3: numeric count badge uses warning (amber), not danger red
    Tabs: { inkBarColor: kaColors.gold, itemSelectedColor: kaColors.gold },
    Table: { headerBg: kaColors.card, headerColor: kaColors.gold, rowHoverBg: kaColors.muted },
    Modal: { headerBg: kaColors.card, titleColor: kaColors.gold },
    Steps: { colorPrimary: kaColors.gold },
    // v3: control fill is the muted tone; the focus/active edge is the 1.4.11 borderStrong (no longer gold)
    Input: { colorBgContainer: kaColors.muted, activeBorderColor: kaColors.borderStrong },
    Select: { colorBgContainer: kaColors.muted, activeBorderColor: kaColors.borderStrong },
    Progress: { defaultColor: kaColors.gold },
  },
};

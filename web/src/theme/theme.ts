// KA theme — Design System v2.1 §5. The only theme: darkAlgorithm with KA seeds.
// No light mode, no theme toggle (client decision 23 Jul 2026).
import { theme as antdTheme } from 'antd';
import type { ThemeConfig } from 'antd';

export const kaColors = {
  background: '#0F0B15',
  card: '#1A1326',
  foreground: '#F4F4F5',
  gold: '#C9A962',
  goldHover: '#D4B876',
  muted: '#1E1729',
  mutedForeground: '#A1A1AA',
  border: '#2A2235', // decorative separators: dividers, card edges, table lines (1.4.11-exempt)
  borderStrong: '#726889', // control boundaries: inputs, selects, buttons — 3.48:1 on card, 3.76:1 on bg
  success: '#22C55E',
  warning: '#FBBF24',
  danger: '#EF4444',
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
    colorPrimary: kaColors.gold, // gold leads in dark
    colorInfo: kaColors.gold,
    colorError: kaColors.danger,
    colorSuccess: kaColors.success,
    colorWarning: kaColors.warning,
    colorBgLayout: kaColors.background,
    colorBgContainer: kaColors.card,
    // 1.4.11 split (design review, 23 Jul 2026): controls get the 3.0-compliant
    // boundary; decorative separators keep the quiet aubergine line
    colorBorder: kaColors.borderStrong,
    colorBorderSecondary: kaColors.border,
    controlOutline: kaColors.gold, // solid — 8.01:1 on card; 0.35 alpha blended to 2.06
    colorTextSecondary: kaColors.mutedForeground, // §3.1 mapping — one source of truth
    fontFamilyCode: "'JetBrains Mono', monospace",
  },
  components: {
    Layout: { siderBg: kaColors.background, headerBg: kaColors.card },
    Menu: { darkItemSelectedColor: kaColors.gold, darkItemHoverBg: kaColors.muted },
    Button: { primaryColor: kaColors.background }, // dark text on gold
    Tabs: { inkBarColor: kaColors.gold, itemSelectedColor: kaColors.gold },
    Table: { headerBg: kaColors.card, headerColor: kaColors.gold, rowHoverBg: kaColors.muted },
    Modal: { headerBg: kaColors.card, titleColor: kaColors.gold },
    Steps: { colorPrimary: kaColors.gold },
    Input: { activeBorderColor: kaColors.gold },
    Progress: { defaultColor: kaColors.gold },
  },
};

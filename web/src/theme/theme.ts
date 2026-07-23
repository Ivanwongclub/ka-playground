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
  border: '#2A2235',
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
  borderRadius: 10, // brief rounded-lg; sm=6 md=8 via component tokens
  fontFamily: "Inter, 'Noto Sans HK', 'Noto Sans SC', system-ui, sans-serif",
  fontSize: 14,
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
    colorBorder: kaColors.border,
    controlOutline: 'rgba(201,169,98,0.35)',
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

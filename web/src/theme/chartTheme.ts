// Shared chart theme — Design System v2.1 §7.
// Ant Design Charts does NOT inherit ConfigProvider; every chart receives this object.
import { kaColors } from './theme';

// Categorical series: Okabe-Ito, never brand colours stretched into a palette (§7)
export const okabeIto = [
  '#E69F00',
  '#56B4E9',
  '#009E73',
  '#F0E442',
  '#0072B2',
  '#D55E00',
  '#CC79A7',
  '#000000',
] as const;

// Sequential (heatmaps): Viridis. Diverging: RdBu. (§7)
export const viridis = ['#440154', '#414487', '#2A788E', '#22A884', '#7AD151', '#FDE725'] as const;
export const rdBu = ['#B2182B', '#EF8A62', '#FDDBC7', '#D1E5F0', '#67A9CF', '#2166AC'] as const;

// G2 v5 theme object shared by every chart
export const kaChartTheme = {
  type: 'classicDark',
  view: { viewFill: 'transparent', plotFill: 'transparent', mainFill: 'transparent' },
  axis: {
    labelFill: kaColors.mutedForeground,
    titleFill: kaColors.mutedForeground,
    lineStroke: kaColors.border,
    tickStroke: kaColors.border,
    gridStroke: kaColors.border,
    gridStrokeOpacity: 0.6,
  },
  legend: { itemLabelFill: kaColors.mutedForeground },
  color: kaColors.gold, // single-series charts use gold
  category10: [...okabeIto],
  category20: [...okabeIto],
} as const;

// Convenience: baseline props merged into every AntD chart config
export const kaChartDefaults = {
  theme: kaChartTheme,
  animate: { enter: { type: 'fadeIn' as const } },
};

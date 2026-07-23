# KA Playground — Design System v2.1 (Ant Design Edition — Complete)

**Date:** 23 July 2026 · **Supersedes:** Platform Brief Part 2 (Tailwind/shadcn edition)
**Sources of truth:** KA website demo (aubergine `#1A1326` · gold `#C9A962`) · Platform Brief §2.1–2.12 (all token values carried over) · Spec v4.2 Part L (positioning register)
**Client decision 23 Jul 2026: DARK MODE ONLY — light mode removed from scope.**
**Place in:** `ka-build/` — referenced by Sprint S00 (theming) as its definition of done.

Every value below is the brief's value re-expressed as Ant Design 5 configuration. Where the brief and spec disagreed, the resolution is stated inline. Nothing here is a new design decision except the two flagged items (§10).

---

## 1. Philosophy (unchanged, binding)

Premium restraint — private-bank portfolio dashboard, not playful edtech. One decision per page. Progressive disclosure. Gold is earned, never decorative: no confetti, no streak counters, no peer leaderboards; progress is thin rings and stepped bars. Bilingual by default (EN/繁中/简中 via i18n keys). No technical jargon in user-facing strings.

---

## 2. Token architecture

Ant Design 5 = seed → map → alias tokens via `ConfigProvider`. **One theme only: `darkAlgorithm` with the KA seeds.** No theme toggle ships; `cssVar: true` retained for token hygiene, `hashed: false` (single antd version). Removing light mode deletes a whole test dimension — every screen is verified once, not twice.

**The one rule that prevents the classic failure:** the app is wrapped in antd's `<App>` component and all toasts/confirms use `App.useApp()` — statically-called `message/notification/Modal` render in a separate React root and would come out default-blue.

## 3. Colour tokens — brief → AntD mapping

### 3.1 The palette (dark, sole theme)
| Brief token | Hex | AntD token |
|---|---|---|
| `--background` | `#0F0B15` | `colorBgLayout` |
| `--card` | `#1A1326` | `colorBgContainer` |
| `--foreground` | `#F4F4F5` | `colorText` |
| `--primary` (gold in dark) | `#C9A962` | `colorPrimary` **switches to gold in dark** |
| `--primary-hover` | `#D4B876` | `colorPrimaryHover` |
| `--muted` | `#1E1729` | `colorFillTertiary` |
| `--muted-foreground` | `#A1A1AA` | `colorTextSecondary` |
| `--border` | `#2A2235` | `colorBorderSecondary` — **decorative separators only**: dividers, card edges, table lines (WCAG 1.4.11-exempt) |
| `--border-strong` | `#726889` | `colorBorder` — **control boundaries**: inputs, selects, buttons, checkboxes (3.48:1 on card, 3.76:1 on background — passes 1.4.11) |
| `--success` | `#22C55E` | `colorSuccess` (brightened for dark) |
| `--warning` | `#FBBF24` | `colorWarning` (brightened) |

Gold is `colorPrimary`, full stop. The aubergine pair (`#0F0B15` canvas / `#1A1326` surfaces) is the permanent chrome. The brief's light-mode tables are retired; §3.1 above is authoritative.

### 3.2 Sidebar
Not themed via algorithm — fixed `Menu`/`Layout` component tokens (§5): shell `#0F0B15`, labels `#FFFFFF`, active indicator gold `#C9A962`, hover `#1E1729`, divider `#2A2235`.

### 3.3 Status colours (semantic)
| Status | Colour base | AntD rendering |
|---|---|---|
| Active / Connected / Verified | emerald | `<Tag color="success">` |
| Completed | blue | `<Tag color="processing">` |
| Pending / Queued | amber | `<Tag color="warning">` |
| Draft | zinc | `<Tag color="default">` |
| Failed / Error | red | `<Tag color="error">` |

Category accents (programme coding, unchanged): Language `#6366F1` · STEM `#A855F7` · Arts `#EC4899` · Maths `#F97316` · Featured `#06B6D4`.

**Accents as text (measured on card `#1A1326`, 23 Jul 2026):** STEM 4.56 · Arts 5.11 · Maths 6.43 · Featured 7.42 — all pass AA as text. **Language 4.04 fails and is never used as body text** — chips, bars and borders only. The palette itself is unchanged.

## 4. Typography

| Role | Font | Weight / size | AntD token |
|---|---|---|---|
| H1 | Montserrat | 700 / 32px | `fontSizeHeading1` + heading family override |
| H2 | Montserrat | 600 / 24px | `fontSizeHeading2` |
| H3 | Montserrat | 600 / 18px | `fontSizeHeading3` |
| Body | **Inter** | 400 / 14px | `fontFamily`, `fontSize: 14` |
| Label / UI | Inter | 500 / 13px | `Form`/`Table` component tokens |
| Caption | Inter | 400 / 12px | `fontSizeSM` |
| Mono | JetBrains Mono | 400 / 13px | `fontFamilyCode` |

`fontFamily: "Inter, 'Noto Sans HK', system-ui, sans-serif"` · TC line-height 1.8 (Latin 1.5) · TC minimum 13px · Montserrat letter-spacing −0.01em · antialiased.
*(Resolution: brief says Inter, spec Part L said DM Sans — see §10.)*

## 5. ConfigProvider — the actual theme

```ts
// theme.ts — Sprint S00 deliverable
import { theme as antdTheme, ThemeConfig } from 'antd';

const shared = {
  borderRadius: 10,           // brief rounded-lg; md=8 stays component-level
  borderRadiusSM: 6,
  fontFamily: "Inter, 'Noto Sans HK', 'Noto Sans SC', system-ui, sans-serif",
  fontSize: 14,
  fontSizeHeading1: 32, fontSizeHeading2: 24, fontSizeHeading3: 18,  // §4 type scale
};

export const kaTheme: ThemeConfig = {  // the only theme
  cssVar: true, hashed: false,
  algorithm: antdTheme.darkAlgorithm,
  token: { ...shared,
    colorPrimary: '#C9A962', colorInfo: '#C9A962',       // gold leads in dark
    colorError: '#EF4444', colorSuccess: '#22C55E', colorWarning: '#FBBF24',
    colorBgLayout: '#0F0B15', colorBgContainer: '#1A1326',
    colorBorder: '#726889',           // controls — 1.4.11 compliant (§3.1)
    colorBorderSecondary: '#2A2235',  // decorative separators (§3.1)
    controlOutline: '#C9A962',        // solid gold focus ring — 8.01:1 on card
    colorTextSecondary: '#A1A1AA',    // §3.1 mapping made explicit
  },
  components: {
    Layout: { siderBg: '#0F0B15', headerBg: '#1A1326' },
    Menu:   { darkItemSelectedColor: '#C9A962', darkItemHoverBg: '#1E1729' },
    Button: { primaryColor: '#0F0B15' },                 // dark text on gold
    Tabs:   { inkBarColor: '#C9A962', itemSelectedColor: '#C9A962' },
    Table:  { headerBg: '#1A1326', headerColor: '#C9A962', rowHoverBg: '#1E1729' },
    Modal:  { headerBg: '#1A1326', titleColor: '#C9A962' },
    Steps:  { colorPrimary: '#C9A962' },
    Input:  { activeBorderColor: '#C9A962' },
    Progress: { defaultColor: '#C9A962' },
  },
};
```

Gold CTA buttons are a `variant="gold"` wrapper: `background:#C9A962; color:#1A1326; box-shadow:0 4px 16px rgba(201,169,98,0.3)`.

## 6. ProLayout
`layout="side"` · fixed 240px sider (collapsed 64px) · dark sider both modes (§3.3) · logo slot top, mono-white logo on aubergine · breadcrumb in header · content `max-width: none`, padding 24px (page-x 32px on ≥1280px) · mobile <768px: sider → AntD drawer + 5-item bottom tab bar; tables → card stacks; modals → full-screen drawers.

## 7. Charts (the gap the brief never covered)
Ant Design Charts does **not** inherit ConfigProvider — a shared `kaChartTheme` is built from the same tokens and passed to every chart: background transparent · label/axis colours from `colorTextSecondary` · grid lines from `colorBorder` · single-series charts use gold · **categorical series use Okabe-Ito** (`#E69F00 #56B4E9 #009E73 #F0E442 #0072B2 #D55E00 #CC79A7 #000000`) — brand colours are never stretched into a categorical palette · sequential heatmaps Viridis · diverging RdBu · meaning never by colour alone (labels/icons always).

## 8. Spacing, elevation, motion — and the complete interaction matrix

Radius 6/8/10/16 · shadows: sm `0 1px 3px rgba(0,0,0,.3)`, md `0 4px 12px rgba(0,0,0,.4)`, lg modal `0 20px 60px rgba(0,0,0,.5)`, gold `0 4px 16px rgba(201,169,98,.3)`, gold-lift `0 8px 24px rgba(201,169,98,.45)` · motion: fade-in-up 0.6s, fade-in 0.5s, scale-in 0.4s (modal), slide-in-right 0.3s (drawer); all transforms off under `prefers-reduced-motion` · icons **Lucide React only** at 16/20/24/32 · forms: 40px inputs, 13px labels.

**Every interactive element has a defined hover, focus, active and disabled state — none is left to component defaults:**

| Element | Hover | Focus-visible | Active/pressed | Timing |
|---|---|---|---|---|
| Primary button (gold) | `#D4B876` | gold ring 2px offset 2px | scale(.98) | 150ms |
| **Gold CTA** | `#D4B876` + **shadow escalates to gold-lift + translateY(−1px)** | gold ring | scale(.98) | 150ms |
| Secondary / outline / ghost btn | bg → `--muted` / border → gold-tint / text → `--fg` | gold ring | scale(.98) | 150ms |
| Danger button | `#DC2626` | gold ring | scale(.98) | 150ms |
| Icon button | bg `--muted`, icon → gold, border appears | gold ring | scale(.95) | 150ms |
| Card (interactive) | translateY(−4px) + shadow sm→md | gold ring | — | 200ms |
| Card (static/KPI) | **no motion** — cursor default; only elements that navigate may move | — | — | — |
| Sidebar nav item | bg `#1E1729` | gold ring inset | — | 150ms |
| Sidebar nav (selected) | stays gold-on-`#1E1729`, no further change | — | — | — |
| Table row | bg `#241B31` | row outline on keyboard nav | — | 150ms |
| Tab (unselected) | text → `--fg`, bg `--muted` top-rounded | gold ring | — | 150ms |
| Tab (selected) | no change (already gold ink bar) | — | — | — |
| Input / select | border → gold at 50% | border gold + ring `rgba(201,169,98,.2)` 3px | — | 150ms |
| Text link | gold underline appears | gold ring | — | 150ms |
| Tag/badge (informational) | translateY(−1px) only if clickable; else none | — | — | 150ms |
| Dropdown menu item | bg `--muted`, text → `--fg` | gold ring | — | 100ms |
| Checkbox / switch / radio | outline gold-tint | gold ring | — | 150ms |
| Steps (Activity Tracker) | tooltip with gate summary on hover; dots themselves do not move | gold ring | — | — |
| Pagination / breadcrumb | text → gold | gold ring | — | 150ms |
| Chart datum | tooltip + datum highlight (opacity of others → .45) | — | — | 100ms |
| Disabled (anything) | **no hover response**, opacity .5, cursor-not-allowed | — | — | — |
| Loading button | spinner replaces label, width locked, hover suppressed | — | — | — |

Rules of thumb: hover means *"this responds"* — purely informational surfaces (KPI cards, static tags, selected states) do **not** move; focus-visible is never suppressed anywhere; skeleton shimmer on `--muted` for all loading surfaces.

## 9. Definition of done for Sprint S00 theming
`/style-guide` route renders: every Button/Tag/Tab/Table/Modal/Input/Steps/Progress variant **with all §8 interaction states demonstrable** · a toast + confirm fired via `App.useApp()` showing brand colours (proves the wrapper) · one line chart + one heatmap on `kaChartTheme` · sidebar with gold active + hover states · TC sample paragraph at 1.8 line-height. If any item renders default-blue, S00 is not done.

## 10. Two flagged decisions (default applies unless overridden)
1. **Body font:** Inter (this doc, per brief §2.3) vs DM Sans (spec Part L, from the MVP). **Default: Inter** — better CJK pairing and data-density; the MVP visual difference is negligible. Spec Part L updated to match.
2. **Cream `#F5F0E8`:** the website demo uses it; the brief bans it ("no cream") for the platform's data surfaces. **Default: keep the ban** — cream stays a marketing/website colour, not a platform surface.


## 11. Corporate-image completeness (added — the last two gaps)

**11.1 Logo usage.** Source asset is the King Armour monochrome logo (`KA-Logo-BW-Black-01`). Platform rules: white/mono-light variant on aubergine surfaces (sidebar, dark headers, modal headers); black variant only on white/light surfaces; never on gold; never recoloured to gold or purple; clearspace = the height of the "K" on all sides; minimum render 28px height in the sider, 20px in emails/receipts. Receipts and consent PDFs carry the black variant on white. Until colour vectors arrive from the client (Open Decision), the mono logo is the only permitted mark.

**11.2 Imagery.** Match king-armour.com's editorial photography register: real environments, natural light, no stock-smile clichés, no illustration/cartoon styles, no emoji in UI copy. Duotone overlay for hero surfaces: aubergine `#1A1326` at 60–80% over photography, text in white/gold on top. Programme card images follow the existing scheme-image set from the MVP assets.

**11.3 Gold discipline (measurable).** Gold appears in at most: 1 primary CTA per view, active/selected indicators, focus rings, badges of achievement, and data-emphasis single-series. If a screen renders >10% of its pixels in gold, it is wrong — gold is punctuation, not paint.

**11.4 Voice.** English: concise, assured, no exclamation marks, no jargon (FRIENDLY label map applies). Chinese: 繁體 default, formal register (敬語 in parent-facing consent/payment copy), 简体 via i18n toggle only.

**11.5 Accessibility floor.** Text contrast ≥4.5:1 (verified: `#1A1326` on white = 16.9:1; white on `#1A1326` = 16.9:1; `#0F0B15` on gold `#C9A962` = 8.1:1 — all pass AA/AAA); gold on white is decorative-only (2.2:1 — never body text); focus-visible never suppressed; all charts label-carrying per §7.

**Non-text contrast (WCAG 1.4.11, reviewed 23 Jul 2026).** Control boundaries and focus indicators need ≥3.0:1; decorative separators are exempt. Verified: control border `#726889` = 3.48:1 on card / 3.76:1 on background · focus ring solid gold `#C9A962` = 8.01:1 on card (the original `rgba(201,169,98,0.35)` blended to 2.06 and was replaced) · decorative `#2A2235` intentionally quiet, exempt · category accents as text per §3.3 note. Surface-on-surface (card 1.08:1 on background) relies on spacing and elevation, not edge contrast — by design.

With §11, the design system is complete for corporate-image purposes: tokens, components, layout, charts, motion, logo, imagery, voice, accessibility.

## 12. Surface states — empty, loading, error (every surface, no defaults)

**12.1 Empty states.** Pattern everywhere: Lucide icon 32px in `--muted-fg` → H3 (Montserrat 18) → one caption line → at most one CTA (gold only if it is *the* next action). No illustrations, no cartoons, no emoji — corporate register. Canonical copy:

| Surface | H3 | Caption | CTA |
|---|---|---|---|
| Activity Tracker, no team | Join or create a team to begin | The Tracker unlocks once your team is formed | Go to Team Formation (gold) |
| Catalogue, no results | No programmes match | Try removing a filter | Clear filters (ghost) |
| Table, no rows | Nothing here yet | New records will appear automatically | — |
| Approval queue, empty | All caught up | No pending approvals | — |
| Chart, no data | No data for this period | Adjust the date range above | — |
| Search, no hits | No matches for “{query}” | Check spelling or broaden the term | — |

**12.2 Loading.** Skeletons on `--muted` shimmer, shaped like the content: card = title bar + 3 lines; table = header + 5 rows; KPI = number block; chart = axes + block; avatar = circle. Spinners exist **only inside buttons** (label swaps, width locked). Never a full-page spinner after first paint; the shell renders, widgets stream in. Skeleton minimum display 300ms (no flash).

**12.3 Errors.** Field errors: 12px danger text under the field + danger border — never a toast for a field. Form-level: banner atop the form listing blockers, anchor-links to fields. Async operation failure: danger toast, sticky until dismissed, with retry action where the op is retryable. Full-page 403/404/500: empty-state pattern with mono error code (`ERR-403`), Back + Home actions; 500 adds “reference ID” from the request ID for support. Network loss: single sticky banner “Connection lost — retrying…”, auto-clears. Chart error: inline “Couldn’t load — Retry” in the chart body, never a broken canvas.

## 13. Overlays — modal, drawer, toast, tooltip, popconfirm

| Overlay | Use when | Spec |
|---|---|---|
| **Modal** | Confirm or ≤2 fields | Widths 420 / 560 / 720 · scale-in 0.4s · aubergine header, gold title · footer right-aligned, primary rightmost |
| **Drawer** | Any form, any detail view | 480 (form) / 720 (detail) from right · slide-in 0.3s · leave-guard if dirty |
| **Destructive confirm** | Delete, disband, revoke | Modal + danger button; **irreversible actions require typing the entity name** (disband team, revoke certificate) |
| **Popconfirm** | Single-step minor destructive (remove a row) | Anchored, danger confirm, Esc cancels |
| **Toast** | Async outcome | Top-right, max stack 3, success/info auto-dismiss 4s, warning 6s, error sticky · via `App.useApp()` only |
| **Tooltip** | Names, truncations, icon labels | 300ms delay, 12px, never contains actions, never required to operate |

Z-index scale (fixed, no ad-hoc values): content 0 · sticky header 10 · sider 20 · drawer 100 · modal 200 · toast 300 · tooltip 400.

## 14. Data display standards

**Tables.** Row 48px (40px compact toggle on dense admin tables) · sticky header · page size 20 (10/50 options) · alignment: text left, numbers right in `tabular-nums`, status as tags, actions right · sort indicator gold · empty cell always “—”, never blank · >7 columns → horizontal scroll with first column pinned · row click opens detail only when the whole row is one entity (else explicit action buttons).

**Numbers & money.** Finance always `tabular-nums`. Currency `HK$1,234.56`; other currencies ISO-prefixed (`SGD 1,234.00`). Percentages 1 dp. Abbreviations (`12.4k`) allowed in KPI widgets only — tables and reports show full figures. Negative money in danger colour with minus, never parentheses.

**Date & time.** Store UTC, render in the user’s timezone (multi-jurisdiction, spec L5). Date `23 Jul 2026` · with time `23 Jul 2026, 14:30 (HKT)` — zone shown whenever viewer zone ≠ event zone. Relative time (“2h ago”) only in feeds, absolute on hover. Deadlines <7 days away render as countdown chips (“3 days left”, warning at <48h, danger at <24h). Locale dates: EN `23 Jul 2026` / 繁中 `2026年7月23日`.

**Identifiers.** Receipt/order/reference IDs in JetBrains Mono with click-to-copy; copied state = gold check 1.5s.

## 15. Forms & validation

Validate on blur + on submit; errors persist until corrected. Message register: instruct, don’t scold — “Enter the student’s English name”, never “Invalid input”. **No asterisks:** required is the default; optional fields are marked “(optional)”. Labels always visible — placeholder is never the label; placeholder shows format examples only. Help text 12px below the field. One-column ≤720px in all wizards. Section spacing 24px with H3 group headers. Wizards autosave drafts with a quiet “Saved · 14:32” stamp; navigation away from a dirty non-autosaving form triggers the leave-guard. Selects with >7 options are searchable; >50 use remote search. Disabled submit buttons carry a tooltip stating what is missing.

## 16. KA-specific components

**Status timeline** (enrolment E5): horizontal dots + connecting line — done = gold filled with check, current = gold 2.5px ring, future = muted; each step carries label, date, and one caption line; the blocking step shows who holds it and a single reminder action (rate-limited server-side).

**Approval queue row:** entity-type chip · title · requester avatar+name · age (“2d”, warning >7d) · Approve (primary) + Return (ghost, opens reason drawer — reason mandatory). Bulk-approve only where the sprint card explicitly allows it; never for consent or refunds.

**Upload dropzone** (per O2 pipeline): idle = dashed `--border`, “Drop file or browse” · drag-over = gold dashed + gold-tint fill · uploading = progress bar (gold) · **scanning = “Checking file…” shimmer (ClamAV step — file not yet visible)** · quarantined = danger state with admin-contact note · done = filename + size + check + remove. Rejected type/size fails inline before upload starts.

**Signature pad** (consent G6): 3:1 canvas, white pad with aubergine ink `#1A1326` **even on the dark canvas** (paper metaphor = evidential weight) · Clear + Undo · Sign button disabled until stroke present + scrolled-to-end + affirmation ticked — three independent gates, each with its own unmet-state hint.

**Avatar:** sizes 24 / 32 / 40 / 56 · fallback = initials, gold on aubergine · leadership frame = 2px gold ring + role chip below at 40px+ · library picker = 6-column grid, selected = gold ring + check.

**Notification bell:** gold dot for unread (no count badge over 9 — “9+”) · panel = 380px drawer, grouped Today / Yesterday / Earlier · unread rows tinted `--muted` · mark-all-read at top · each row deep-links.

**Programme card:** 16:9 image top (rescued MVP assets; gradient fallback per category) · category tag overlaid top-left · title 2-line clamp · meta row (organiser · ages) · footer: period + enrolled count. Whole card hovers (interactive card rules §8).

**Activity Tracker stages:** 32px dots per §8; hover tooltip shows the gate summary (“2 of 3 conditions met — budget approval outstanding”); stage tab click navigates, dot click does not.

## 17. Responsive & mobile interaction system (app-like, not shrunk desktop)

The mobile experience follows **native-app conventions**, not a hamburger-menu website. No hamburger icon exists anywhere.

### 17.1 Breakpoints
| Breakpoint | Behaviour |
|---|---|
| ≥1280 | Full: 240px sider, page-x 32px, all grids |
| 1024–1279 | page-x 24px; 3-col grids → 2 |
| 768–1023 | Sider collapses to 64px icon rail (labels in tooltips); 2-col → 1; tables gain pinned first column |
| <768 | **App shell** — everything in 17.2–17.8 |

### 17.2 Navigation — bottom tab bar + drawer (no hamburger)
- **Bottom tab bar**, fixed, 5 items, 56px + safe-area inset. Active = gold icon + label; inactive = muted icon only. Per role:
  - Student: Dashboard · Tracker · Team · Learn · Profile
  - Parent: Dashboard · Children · Consent · Payments · Profile
  - Teacher: Dashboard · Approvals · Teams · Sessions · Profile
  - Admin: Dashboard · Approvals · Programmes · Reports · Profile
- **Navigation drawer** (left, 85% width, scrim behind) holds everything not in the tab bar — Finance, Notifications, Reports, Help, Settings, sign-out. Opened by **avatar tap** or **left-edge swipe-in**; closed by scrim tap, left swipe, or back. It is a full nav list with the user card on top — a drawer, not a hamburger dropdown.

### 17.3 Sheets replace desktop overlays
| Desktop | Mobile |
|---|---|
| Right drawer (forms/detail) | **Bottom sheet** — drag handle, snap points at 50% and 92%, drag between them |
| Modal ≤2 fields | Bottom sheet at content height |
| Full detail page sections | Full-screen sheet with top bar (title + Close) |
| Popconfirm | Action sheet (buttons stacked, Cancel last) |
| Toast | Same, but above the tab bar |

**Swipe-to-close:** every sheet dismisses by downward drag past 30% velocity-aware threshold — **except** dirty forms (leave-guard intercepts with a keep/discard action sheet) and destructive confirmations (explicit button only; a destructive action must never be dismissible by an ambiguous gesture, and equally never *confirmed* by one).

### 17.4 Gestures
| Gesture | Where | Action |
|---|---|---|
| Swipe down | Sheets, image lightbox | Close (guards per 17.3) |
| Left-edge swipe right | Anywhere | Open nav drawer |
| Horizontal swipe | Activity Tracker stage tabs, gallery | Move between stages/images (tabs stay tappable too) |
| Row swipe (trailing) | Approval queue | Reveal Approve / Return actions |
| Row swipe (trailing) | Notification list | Mark read / delete |
| Pull down | Dashboard, lists | Refresh with gold spinner |
| Long-press | IDs (receipt/order no.) | Copy, with "Copied" toast |
Gestures are always accelerators — every gesture action has a visible button equivalent (accessibility §19).

### 17.5 Touch & layout adjustments
Touch targets ≥44px on mobile (40px desktop rule steps up) · hover states map to pressed states (`:active`), no hover dependence · primary CTA docks sticky above the tab bar on flow screens (enrol, sign, pay) · tables → card stacks (primary field + 2 metas + status tag; tap opens full-screen sheet) · charts render single-series with horizontal scroll for time ranges; tap datum = tooltip · page-x 16px.

### 17.6 System behaviours
Safe-area insets respected top and bottom (`env(safe-area-inset-*)`) · layout uses `100dvh` (keyboard-aware) · focused inputs scroll into view above the keyboard; sheets resize rather than hide the field · **browser back closes the topmost sheet first** (history-state per sheet), then navigates — back never skips a layer · scroll position restored on return · momentum scrolling everywhere.

### 17.7 PWA shell (Phase 1, cheap)
Web app manifest + icons (from rescued favicon/logo set) so the platform installs to the home screen full-screen with the aubergine theme colour. No offline mode, no push in Phase 1 — the manifest alone makes it feel like the app it behaves like. (Native app remains Phase 2.)

### 17.8 Z-index & scroll (unchanged from §13)
Sticky table headers; back-to-top after 2 viewport heights; z-scale: content 0 · sticky 10 · tab bar/sider 20 · drawer/sheet 100 · modal 200 · toast 300 · tooltip 400.

## 18. Bilingual display rules

TC line-height 1.8, minimum 13px, **no italics for CJK** (use weight for emphasis). Buttons sized to fit the longer of EN/TC — no truncated CTAs. i18n keys are whole sentences, never concatenated fragments (word order differs). Dates/numbers per locale (§14); currency symbol stays `HK$` in all locales. 简体 shares the TC layout — toggle swaps strings only. Mixed Latin-CJK spacing left to Noto Sans HK metrics — no manual spaces.

## 19. Accessibility beyond contrast

Keyboard: every action reachable; focus order = visual order; skip-to-content link first in DOM; Esc closes any overlay (topmost first); arrow keys navigate menus, tabs and table rows; Enter activates. Screen readers: icons `aria-hidden` with adjacent text or `aria-label`; toasts announce via live region; status is never colour-only (text in every tag); every chart offers a “View as table” toggle rendering the same data. Touch targets ≥40px. `prefers-reduced-motion` kills transforms (kept from §8); `prefers-contrast: more` steps borders up to `#3A3147` and muted text to `#C4C4CC`.

## 20. Icon mapping — one concept, one icon (Lucide)

Modules: Dashboard `layout-dashboard` · Programmes `book-open` · Activity Tracker `route` · Team `users` · Learn `graduation-cap` · Finance `receipt` · Profile `user` · Admin/Settings `settings` · Audit `shield-check` · Reports `bar-chart-3` · Notifications `bell`.
Actions: create `plus` · edit `pencil` · delete `trash-2` · view `eye` · download `download` · export `file-down` · upload `upload` · approve `check` · return/reject `corner-up-left` · remind `bell-ring` · sign `pen-tool` · verify `badge-check` · link `link-2` · filter `sliders-horizontal` · search `search` · calendar `calendar` · money `dollar-sign` · lock `lock` · external/SSO `external-link`.
Rules: never two icons for one concept, never one icon for two; module icons appear only in nav and page headers, action icons only on controls.

## 21. Completeness register

Dimensions covered and where: philosophy §1 · tokens §2–3 · typography §4 · theme code §5 · layout shell §6 · charts §7 · spacing/motion/**full interaction matrix** §8 · S00 definition of done §9 · decisions §10 · logo/imagery/gold/voice/contrast §11 · empty/loading/error §12 · overlays & z-index §13 · data/number/date/ID display §14 · forms & validation §15 · KA components (timeline, approvals, dropzone, signature, avatar, bell, cards, stages) §16 · responsive & mobile-app interaction (drawer nav, bottom tabs, sheets, swipe gestures, PWA) §17 · bilingual §18 · accessibility §19 · icon map §20. A design question not answerable by one of these sections is a defect in this document — raise it and it gets a section, not a patch.

*End.*

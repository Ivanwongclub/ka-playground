## Goal
On mobile, the Profile / Enrolments / Notes / Tasks tab bar currently scrolls horizontally. Make it fit the viewport so it stays "fixed" (no left/right movement).

## Change
File: `src/routes/_authenticated/students.$id.tsx` (line 342–362, tab `<nav>`)

- Remove `overflow-x-auto` and `shrink-0`; distribute tabs evenly across the row on mobile, keep current look on desktop.
- Tighten mobile padding so all 4 tabs fit at 360–390px without truncation; counts shown as `(3)` stay.

Specifically:
- `<nav>`: `flex w-full` (drop `overflow-x-auto`, keep sticky/border/backdrop).
- Each `<button>`: `flex-1 md:flex-none` and reduce horizontal padding on mobile (`px-2 md:px-5`), keep `minHeight: 44` for tap target. Center label.

No changes to desktop appearance, no changes to tab logic, no changes to header card.

## Verification
At 390px and 360px viewports: all 4 tabs visible in one row, no horizontal scroll, active underline still aligned, taps still switch tabs.

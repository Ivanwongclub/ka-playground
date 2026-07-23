# Shared components

**Rule (binding, set S00):** any component used by more than one screen lives here,
not in a feature folder. Later sprints extend this directory rather than duplicating —
S05 must not rebuild what S04A already made.

- Check here **before** writing a component; extend an existing one over forking it.
- Promotion is part of the change: the moment a second screen needs a feature-local
  component, the same commit moves it here.
- KA-specific composites from Design System §16 (status timeline, approval queue row,
  upload dropzone, signature pad, avatar, notification bell, programme card) land here
  as their sprints build them.
- Mobile primitives live in `../mobile/` (BottomTabBar, NavDrawer, BottomSheet);
  everything else shared goes here.
- Every user-facing string via i18n keys, tokens from `theme/theme.ts` — no local hexes.

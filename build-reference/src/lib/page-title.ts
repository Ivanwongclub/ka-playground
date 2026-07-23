import { useEffect, useSyncExternalStore } from "react";

let current: string | null = null;
const listeners = new Set<() => void>();

function emit() {
  listeners.forEach((l) => l());
}

export function setPageTitle(title: string | null) {
  if (current === title) return;
  current = title;
  emit();
}

export function usePageTitleOverride(): string | null {
  return useSyncExternalStore(
    (cb) => {
      listeners.add(cb);
      return () => listeners.delete(cb);
    },
    () => current,
    () => current,
  );
}

/** Set the topbar title for the lifetime of a component. Clears on unmount. */
export function usePageTitle(title: string | null | undefined) {
  useEffect(() => {
    if (!title) return;
    setPageTitle(title);
    return () => setPageTitle(null);
  }, [title]);
}

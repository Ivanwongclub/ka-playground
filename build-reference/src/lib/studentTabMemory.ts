export const TAB_IDS = ["profile", "enrolments", "notes", "tasks"] as const;
export type TabId = (typeof TAB_IDS)[number];

const KEY = "ka:lastStudentTab";

export function getLastStudentTab(): TabId {
  try {
    const raw = sessionStorage.getItem(KEY);
    if (raw && (TAB_IDS as readonly string[]).includes(raw)) {
      return raw as TabId;
    }
  } catch {
    // sessionStorage unavailable (SSR, privacy mode) — fall through
  }
  return "profile";
}

export function setLastStudentTab(tab: TabId): void {
  try {
    sessionStorage.setItem(KEY, tab);
  } catch {
    // ignore
  }
}

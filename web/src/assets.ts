// ASSET-MANIFEST §3: Phase 1 serves the rescued tree from public/assets/; the
// OSS swap later is exactly one env var (VITE_ASSET_BASE_URL) — paths identical.
export const ASSET_BASE_URL: string =
  (import.meta.env.VITE_ASSET_BASE_URL as string | undefined) ?? '/assets';

export function asset(path: string): string {
  return `${ASSET_BASE_URL}/${path.replace(/^\//, '')}`;
}

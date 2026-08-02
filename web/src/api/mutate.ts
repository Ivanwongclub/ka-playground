// S-UX3-1 — the write-side counterpart to useResource. Every mutating admin action goes
// through this: POST with res.ok checked, the server's error message extracted (422/403/409
// are surfaced, never swallowed), network failure caught. The caller decides the UX
// (message.error + which copy), and re-fetches its queue on success (refresh-after-mutate).
import { authFetch } from '../auth/session';

export interface MutateResult {
  ok: boolean;
  status: number;
  message?: string; // the server's message, when present
}

export async function mutate(url: string, body?: unknown): Promise<MutateResult> {
  try {
    const res = await authFetch(url, {
      method: 'POST',
      headers: body === undefined ? undefined : { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (res.ok) return { ok: true, status: res.status };
    let message: string | undefined;
    try {
      message = ((await res.json()) as { message?: string }).message;
    } catch {
      /* no JSON body */
    }
    return { ok: false, status: res.status, message };
  } catch {
    return { ok: false, status: 0 };
  }
}

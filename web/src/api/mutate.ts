// S-UX3-1 — the write-side counterpart to useResource. Every mutating admin action goes
// through this: POST with res.ok checked, the server's error message extracted (422/403/409
// are surfaced, never swallowed), network failure caught. The caller decides the UX
// (message.error + which copy), and re-fetches its queue on success (refresh-after-mutate).
import { authFetch } from '../auth/session';

export interface MutateResult {
  ok: boolean;
  status: number;
  message?: string; // the server's message, when present
  data?: unknown; // the parsed success body, when the server returns JSON (e.g. a minted payment link)
}

export async function mutate(url: string, body?: unknown): Promise<MutateResult> {
  try {
    // FormData (evidence upload, BI-10) rides the same wrapper: no Content-Type header so the browser
    // sets the multipart boundary; JSON bodies are stringified as before. Same res.ok/error handling.
    const isForm = body instanceof FormData;
    const res = await authFetch(url, {
      method: 'POST',
      headers: body === undefined || isForm ? undefined : { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });
    if (res.ok) {
      let data: unknown;
      try {
        data = await res.json();
      } catch {
        /* no JSON body — fine, callers that don't need it ignore data */
      }
      return { ok: true, status: res.status, data };
    }
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

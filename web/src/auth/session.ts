// Token session (Sanctum bearer). 12h idle / 30d remember enforcement is
// server-side; the client just stores, attaches and clears.
const KEY = 'ka.token';

export const getToken = (): string | null => localStorage.getItem(KEY);
export const setToken = (token: string): void => localStorage.setItem(KEY, token);
export const clearToken = (): void => localStorage.removeItem(KEY);

export async function authFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const token = getToken();
  const response = await fetch(input, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init.headers ?? {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (response.status === 401) {
    clearToken();
    window.location.assign('/login');
  }
  return response;
}

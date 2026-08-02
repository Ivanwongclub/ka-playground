// S-UX1 — the SPA persists only a bearer token, so on load it does not know who is
// signed in. This provider fetches GET /api/me once and exposes the caller's identity
// plus their EFFECTIVE PERMISSION SET, which drives role-aware nav and the user menu.
// Nav-hiding built on `has()` is UX only — every endpoint keeps its own server gate.
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { authFetch } from './session';

export interface Identity {
  id: number;
  name: string;
  role: string;
  permissions: string[];
}

interface IdentityState {
  identity: Identity | null;
  loading: boolean;
  has: (permission: string) => boolean;
}

const IdentityContext = createContext<IdentityState>({
  identity: null,
  loading: true,
  has: () => false,
});

export function IdentityProvider({ children }: { children: ReactNode }) {
  const [identity, setIdentity] = useState<Identity | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let alive = true;
    void authFetch('/api/me')
      .then((r) => (r.ok ? (r.json() as Promise<Identity>) : null))
      .then((data) => {
        if (!alive) return;
        setIdentity(data);
        setLoading(false);
      })
      .catch(() => {
        if (alive) setLoading(false);
      });
    return () => {
      alive = false;
    };
  }, []);

  const has = (permission: string) => identity?.permissions.includes(permission) ?? false;

  return (
    <IdentityContext.Provider value={{ identity, loading, has }}>
      {children}
    </IdentityContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export const useIdentity = (): IdentityState => useContext(IdentityContext);

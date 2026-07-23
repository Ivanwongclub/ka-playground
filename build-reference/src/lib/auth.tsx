import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import type { Session } from "@supabase/supabase-js";
import { supabase } from "@/integrations/supabase/client";

export type Role = "admin" | "school" | "teacher" | "parent" | "student";

export interface KAUser {
  id: string;
  email: string;
  full_name: string;
  full_name_zh: string | null;
  role: Role;
  region: string | null;
  language: string | null;
}

interface AuthContextValue {
  session: Session | null;
  user: KAUser | null;
  loading: boolean;
  signIn: (email: string, password: string) => Promise<{ error: string | null }>;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

const inflight = new Map<string, Promise<KAUser | null>>();

async function loadProfile(userId: string): Promise<KAUser | null> {
  const existing = inflight.get(userId);
  if (existing) return existing;

  const p = (async () => {
    try {
      const { data, error } = await supabase
        .from("users")
        .select("id, email, full_name, full_name_zh, role, region, language")
        .eq("id", userId)
        .maybeSingle();
      if (error) {
        console.error("Failed to load profile", error);
        return null;
      }
      return (data as KAUser | null) ?? null;
    } catch (e) {
      console.error("Failed to load profile", e);
      return null;
    }
  })();

  inflight.set(userId, p);
  try {
    return await p;
  } finally {
    inflight.delete(userId);
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null);
  const [user, setUser] = useState<KAUser | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let lastProfileUserId: string | null = null;

    // Single source of truth — onAuthStateChange fires INITIAL_SESSION
    // synchronously on subscribe with the stored session, so no separate
    // getSession() call is needed (which was causing a double loadProfile).
    const { data: { subscription } } = supabase.auth.onAuthStateChange((_event, newSession) => {
      setSession(newSession);
      // Unblock the shell immediately on session resolve — don't wait on
      // the public.users profile fetch. Profile streams in below.
      setLoading(false);

      const uid = newSession?.user?.id ?? null;
      if (!uid) {
        setUser(null);
        lastProfileUserId = null;
        return;
      }
      // Avoid re-fetching the same profile on every token refresh
      if (uid === lastProfileUserId) return;
      lastProfileUserId = uid;

      // Defer to avoid running inside the auth callback (Supabase deadlock guard)
      setTimeout(() => {
        loadProfile(uid).then((p) => setUser(p));
      }, 0);
    });

    return () => subscription.unsubscribe();
  }, []);

  const signIn = async (email: string, password: string) => {
    const { error } = await supabase.auth.signInWithPassword({ email, password });
    if (error) return { error: error.message };
    return { error: null };
  };

  const signOut = async () => {
    await supabase.auth.signOut();
    setUser(null);
    setSession(null);
  };

  return (
    <AuthContext.Provider value={{ session, user, loading, signIn, signOut }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}

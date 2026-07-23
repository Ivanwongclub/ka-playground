import { createFileRoute, Outlet, useNavigate } from "@tanstack/react-router";
import { useEffect, useMemo } from "react";
import { AppShell } from "@/components/shell/AppShell";
import { useAuth, type KAUser, type Role } from "@/lib/auth";

export const Route = createFileRoute("/_authenticated")({
  component: AuthenticatedLayout,
});

function AuthenticatedLayout() {
  const { session, user, loading } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    if (!loading && !session) navigate({ to: "/login" });
  }, [loading, session, navigate]);

  // Render the shell as soon as a session exists; the public.users row may
  // still be loading. Fall back to a minimal user derived from the JWT so
  // the post-login screen never hangs waiting on the profile fetch.
  // Key on the stable user id so token refreshes don't rebuild the object
  // and re-render the whole shell (Sidebar + Topbar) on every auth tick.
  const sessionUserId = session?.user?.id ?? null;
  const fallbackUser = useMemo<KAUser | null>(() => {
    if (!session?.user) return null;
    const meta = (session.user.user_metadata ?? {}) as Record<string, unknown>;
    const role = (typeof meta.role === "string" ? meta.role : "parent") as Role;
    return {
      id: session.user.id,
      email: session.user.email ?? "",
      full_name: (typeof meta.full_name === "string" && meta.full_name) || session.user.email || "",
      full_name_zh: typeof meta.full_name_zh === "string" ? meta.full_name_zh : null,
      role,
      region: typeof meta.region === "string" ? meta.region : "HK",
      language: typeof meta.language === "string" ? meta.language : "en",
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionUserId]);

  // Block only while we genuinely don't know auth state yet. Once a session
  // exists, render the shell immediately using the fallback user derived from
  // the JWT — don't wait on the public.users profile fetch.
  if (loading && !session) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-bg">
        <div className="text-sm text-muted-fg">Loading…</div>
      </div>
    );
  }
  if (!session) return null;

  const effectiveUser = user ?? fallbackUser;
  if (!effectiveUser) return null;

  return (
    <AppShell user={effectiveUser}>
      <Outlet />
    </AppShell>
  );
}

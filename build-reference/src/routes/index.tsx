import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect } from "react";
import { useAuth } from "@/lib/auth";

export const Route = createFileRoute("/")({
  component: Index,
});

function Index() {
  const { session, loading } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    if (loading) return;
    navigate({ to: session ? "/dashboard" : "/login", replace: true });
  }, [loading, session, navigate]);

  return (
    <div className="flex min-h-dvh items-center justify-center bg-bg text-sm text-muted-fg">
      Loading…
    </div>
  );
}

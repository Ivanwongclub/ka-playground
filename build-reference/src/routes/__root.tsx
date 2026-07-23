import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  Outlet,
  Link,
  createRootRouteWithContext,
  useRouter,
  HeadContent,
  Scripts,
} from "@tanstack/react-router";

import appCss from "../styles.css?url";
import { AuthProvider } from "@/lib/auth";

function NotFoundComponent() {
  return (
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-bg px-4">
      {/* Ambient gold orbs */}
      <div
        aria-hidden
        className="pointer-events-none absolute -top-32 -left-32 h-96 w-96 rounded-full opacity-30 blur-3xl"
        style={{ background: "radial-gradient(circle, var(--gold) 0%, transparent 70%)" }}
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-40 -right-32 h-[28rem] w-[28rem] rounded-full opacity-20 blur-3xl"
        style={{ background: "radial-gradient(circle, var(--gold) 0%, transparent 70%)" }}
      />

      <div className="relative z-10 max-w-lg text-center">
        <div
          className="mx-auto inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-wider"
          style={{ background: "var(--gold-soft)", color: "var(--gold)" }}
        >
          <span className="inline-block h-1.5 w-1.5 rounded-full bg-gold animate-pulse" />
          Error 404
        </div>

        <h1
          className="mt-6 text-fg tracking-tight"
          style={{
            fontFamily: "var(--font-heading)",
            fontWeight: 800,
            fontSize: "clamp(72px, 14vw, 144px)",
            lineHeight: 1,
            background: "linear-gradient(135deg, var(--gold), var(--gold-2))",
            WebkitBackgroundClip: "text",
            WebkitTextFillColor: "transparent",
            backgroundClip: "text",
          }}
        >
          404
        </h1>

        <h2
          className="mt-4 text-fg"
          style={{ fontFamily: "var(--font-heading)", fontWeight: 700, fontSize: 24 }}
        >
          This page wandered off
        </h2>
        <p className="mt-3 text-sm text-muted-fg">
          The page you're looking for doesn't exist, has been moved, or you may
          have mistyped the address.
        </p>

        <div className="mt-7 flex flex-wrap items-center justify-center gap-2">
          <Link
            to="/"
            className="inline-flex items-center justify-center rounded-full bg-gold px-5 py-2.5 text-sm font-bold text-black transition-colors hover:bg-gold/90"
          >
            Back to home
          </Link>
          <Link
            to="/dashboard"
            className="inline-flex items-center justify-center rounded-full border border-border bg-card px-5 py-2.5 text-sm font-semibold text-fg transition-colors hover:bg-mut"
          >
            Open dashboard
          </Link>
        </div>

        <div className="mt-10 text-[11px] uppercase tracking-wider text-muted-fg">
          Armour Academy
        </div>
      </div>
    </div>
  );
}

function ErrorComponent({ error, reset }: { error: Error; reset: () => void }) {
  console.error(error);
  const router = useRouter();

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="text-xl font-semibold tracking-tight text-foreground">
          This page didn't load
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Something went wrong on our end. You can try refreshing or head back home.
        </p>
        <div className="mt-6 flex flex-wrap justify-center gap-2">
          <button
            onClick={() => {
              router.invalidate();
              reset();
            }}
            className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
          >
            Try again
          </button>
          <a
            href="/"
            className="inline-flex items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-accent"
          >
            Go home
          </a>
        </div>
      </div>
    </div>
  );
}

export const Route = createRootRouteWithContext<{ queryClient: QueryClient }>()({
  head: () => ({
    meta: [
      { charSet: "utf-8" },
      { name: "viewport", content: "width=device-width, initial-scale=1" },
      { title: "Armour Academy" },
      { name: "description", content: "Kings Armour Education — Programme Management Platform" },
      { name: "author", content: "Kings Armour Education" },
      { property: "og:title", content: "Armour Academy" },
      { property: "og:description", content: "Kings Armour Education — Programme Management Platform" },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary" },
      { name: "twitter:title", content: "Armour Academy" },
      { name: "twitter:description", content: "Kings Armour Education — Programme Management Platform" },
      { property: "og:image", content: "https://pub-bb2e103a32db4e198524a2e9ed8f35b4.r2.dev/b0ea6fc5-1bcd-49b5-9cd8-fa06c03af714/id-preview-8a236d11--fcddef8f-8214-44dc-ad30-72ff11b978aa.lovable.app-1779201353541.png" },
      { name: "twitter:image", content: "https://pub-bb2e103a32db4e198524a2e9ed8f35b4.r2.dev/b0ea6fc5-1bcd-49b5-9cd8-fa06c03af714/id-preview-8a236d11--fcddef8f-8214-44dc-ad30-72ff11b978aa.lovable.app-1779201353541.png" },
    ],
    links: [
      { rel: "stylesheet", href: appCss },
      { rel: "preconnect", href: "https://fonts.googleapis.com" },
      { rel: "preconnect", href: "https://fonts.gstatic.com", crossOrigin: "anonymous" },
      {
        rel: "stylesheet",
        href: "https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=DM+Sans:wght@400;500;600&family=Noto+Sans+HK:wght@400;500&display=swap",
      },
    ],
  }),
  shellComponent: RootShell,
  component: RootComponent,
  notFoundComponent: NotFoundComponent,
  errorComponent: ErrorComponent,
});

function RootShell({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <head>
        <HeadContent />
      </head>
      <body>
        {children}
        <Scripts />
      </body>
    </html>
  );
}

function RootComponent() {
  const { queryClient } = Route.useRouteContext();

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Outlet />
      </AuthProvider>
    </QueryClientProvider>
  );
}

import { Link, useNavigate, useRouterState } from "@tanstack/react-router";
import { useEffect, useRef, useState } from "react";
import { Menu } from "lucide-react";
import { ThemeToggle } from "@/components/shared/ThemeToggle";
import {
  BellIcon,
  ChevronDownIcon,
  GlobeIcon,
  SearchIcon,
} from "@/components/icons";
import { useAuth, type KAUser } from "@/lib/auth";
import { usePageTitleOverride } from "@/lib/page-title";
import { cn } from "@/lib/utils";

const PAGE_TITLES: Record<string, string> = {
  "/dashboard": "Dashboard",
  "/playground": "Playground",
  "/students": "Student",
  "/system": "System",
  "/profile": "My Profile",
};

function resolveTitle(pathname: string): string {
  if (PAGE_TITLES[pathname]) return PAGE_TITLES[pathname];
  // Derive from first path segment for unmapped routes (e.g. /students/abc → "Student")
  const seg = pathname.split("/").filter(Boolean)[0];
  if (!seg) return "Dashboard";
  const mapped = PAGE_TITLES["/" + seg];
  if (mapped) return mapped;
  return seg.charAt(0).toUpperCase() + seg.slice(1);
}

const DEMO_ACCOUNTS: { email: string; name: string; role: string }[] = [
  { email: "admin@ka.test", name: "Alex Admin", role: "admin" },
  { email: "school@ka.test", name: "Sandra School", role: "school" },
  { email: "teacher@ka.test", name: "David Li", role: "teacher" },
  { email: "parent@ka.test", name: "Sarah Chan", role: "parent" },
  { email: "student@ka.test", name: "Tommy Chan", role: "student" },
];

function initials(name: string) {
  return name.split(" ").map((p) => p[0]).join("").slice(0, 2).toUpperCase();
}

function Breadcrumb({ pathname, override }: { pathname: string; override: string | null }) {
  const isDetail = pathname.startsWith("/playground/") && pathname !== "/playground";
  if (isDetail) {
    return (
      <nav className="flex items-center gap-2 font-heading">
        <Link
          to="/playground"
          className="text-[15px] font-medium text-muted-fg transition-colors hover:text-fg"
        >
          Playground
        </Link>
        <span className="text-gold" aria-hidden>
          /
        </span>
        <span className="text-[16px] font-bold text-fg">{override ?? "Programme"}</span>
      </nav>
    );
  }
  return (
    <h1 className="truncate font-heading text-[16px] font-semibold text-fg max-w-[40vw] md:max-w-none">
      {override ?? resolveTitle(pathname)}
    </h1>
  );
}

function IconButton({
  label,
  onClick,
  children,
  showDot,
}: {
  label: string;
  onClick?: () => void;
  children: React.ReactNode;
  showDot?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className="relative inline-flex h-10 w-10 items-center justify-center rounded-lg bg-transparent text-muted-fg transition-colors hover:bg-mut hover:text-fg md:h-9 md:w-9"
    >
      {children}
      {showDot && (
        <span className="absolute right-2.5 top-2.5 h-1.5 w-1.5 rounded-full bg-danger md:right-2 md:top-2" />
      )}
    </button>
  );
}

export function Topbar({
  user,
  onOpenMobileNav,
}: {
  user: KAUser;
  onOpenMobileNav?: () => void;
}) {
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const override = usePageTitleOverride();
  const { signIn, signOut } = useAuth();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [switching, setSwitching] = useState<string | null>(null);
  const ddRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (ddRef.current && !ddRef.current.contains(e.target as Node)) setOpen(false);
    }
    if (open) document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  async function handleSwitch(email: string) {
    if (email === user.email) return;
    setSwitching(email);
    const { error } = await signIn(email, "demo123!");
    setSwitching(null);
    if (!error) {
      setOpen(false);
      navigate({ to: "/dashboard" });
    }
  }

  async function handleSignOut() {
    setOpen(false);
    await signOut();
    navigate({ to: "/login" });
  }

  return (
    <header className="flex h-[56px] shrink-0 items-center gap-1 border-b border-border bg-bg px-3 safe-pt md:gap-4 md:px-8">
      {/* Mobile hamburger */}
      <button
        type="button"
        onClick={onOpenMobileNav}
        aria-label="Open navigation menu"
        className="-ml-1 inline-flex h-10 w-10 items-center justify-center rounded-lg text-muted-fg transition-colors hover:bg-mut hover:text-fg md:hidden"
      >
        <Menu size={22} strokeWidth={1.8} />
      </button>

      <Breadcrumb pathname={pathname} override={override} />

      {/* Search — full input from md, icon-only below md */}
      <div className="relative ml-auto">
        <div className="hidden md:block relative">
          <SearchIcon
            size={14}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-fg"
          />
          <input
            type="search"
            placeholder="Search students, programmes..."
            className="h-9 w-[240px] rounded-lg border border-border bg-mut pl-9 pr-3 text-[13px] text-fg placeholder:text-muted-fg outline-none transition focus:border-gold focus:ring-2 focus:ring-gold/20 lg:w-[320px]"
          />
        </div>
        <button
          type="button"
          onClick={() => setSearchOpen((v) => !v)}
          aria-label="Search"
          aria-expanded={searchOpen}
          className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted-fg transition-colors hover:bg-mut hover:text-fg md:hidden"
        >
          <SearchIcon size={18} />
        </button>
        {searchOpen && (
          <div className="absolute right-0 top-full z-40 mt-1.5 w-[min(80vw,320px)] rounded-lg border border-border bg-card p-2 shadow-elev md:hidden">
            <div className="relative">
              <SearchIcon
                size={14}
                className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-fg"
              />
              <input
                autoFocus
                type="search"
                placeholder="Search students, programmes..."
                className="h-9 w-full rounded-lg border border-border bg-mut pl-9 pr-3 text-[13px] text-fg placeholder:text-muted-fg outline-none transition focus:border-gold focus:ring-2 focus:ring-gold/20"
              />
            </div>
          </div>
        )}
      </div>

      {/* Right actions */}
      <div className="flex items-center gap-1">
        <IconButton label="Notifications" showDot>
          <BellIcon size={18} />
        </IconButton>
        <div className="hidden md:inline-flex">
          <IconButton label="Language">
            <GlobeIcon size={18} />
          </IconButton>
        </div>
        <ThemeToggle />

        <div className="relative ml-1" ref={ddRef}>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className={cn(
              "flex h-9 items-center gap-2 rounded-lg border border-border bg-transparent pl-1 pr-3 transition-colors hover:bg-mut",
            )}
          >
            <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-gold-gradient text-[10px] font-bold text-bg">
              {initials(user.full_name)}
            </span>
            <span className="hidden text-[13px] font-semibold text-fg sm:inline">{user.full_name}</span>
            <ChevronDownIcon size={14} className="text-muted-fg" />
          </button>
          {open && (
            <div className="absolute right-0 top-full z-50 mt-1.5 w-[260px] rounded-lg border border-border bg-card p-1 shadow-elev">
              <div className="px-2.5 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-fg">
                Signed in
              </div>
              <div className="flex items-center gap-2 rounded-md bg-gold-soft px-2.5 py-2">
                <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-gold-gradient text-[10px] font-bold text-bg">
                  {initials(user.full_name)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="truncate text-xs font-semibold text-fg">{user.full_name}</div>
                  <div className="truncate text-[10px] text-muted-fg">{user.email}</div>
                </div>
                <span className="rounded bg-gold px-1.5 py-0.5 text-[9px] font-bold uppercase text-bg">
                  {user.role}
                </span>
              </div>
              <div className="my-1 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-fg">
                Switch account
              </div>
              {DEMO_ACCOUNTS.filter((a) => a.email !== user.email).map((acc) => (
                <button
                  key={acc.email}
                  onClick={() => handleSwitch(acc.email)}
                  disabled={switching !== null}
                  className={cn(
                    "flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left transition hover:bg-card-elev",
                    switching === acc.email && "opacity-60",
                  )}
                >
                  <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-card-elev text-[10px] font-bold text-fg">
                    {initials(acc.name)}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-xs font-medium text-fg">{acc.name}</div>
                    <div className="truncate text-[10px] text-muted-fg">{acc.email}</div>
                  </div>
                  <span className="rounded bg-mut px-1.5 py-0.5 text-[9px] font-semibold uppercase text-muted-fg">
                    {acc.role}
                  </span>
                </button>
              ))}
              <div className="mt-1 border-t border-border pt-1">
                <button
                  onClick={handleSignOut}
                  className="flex w-full items-center px-2.5 py-2 text-left text-xs font-medium text-fg transition hover:bg-card-elev hover:rounded-md"
                >
                  Sign out
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}

import { Link, useRouterState } from "@tanstack/react-router";
import { LayoutDashboard, Sparkles, Users, Settings, UserRound } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { ChatIcon } from "@/components/icons";
import { cn } from "@/lib/utils";
import type { Role } from "@/lib/auth";

type NavItem = { to: string; label: string; icon: LucideIcon };
type NavGroup = { label: string; items: NavItem[] };

const WORKSPACE_BY_ROLE: Record<Role, NavItem[]> = {
  admin: [
    { to: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
    { to: "/playground", label: "Playground", icon: Sparkles },
    { to: "/students", label: "Student", icon: Users },
  ],
  school: [
    { to: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
    { to: "/playground", label: "Playground", icon: Sparkles },
    { to: "/students", label: "Student", icon: Users },
  ],
  teacher: [
    { to: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
    { to: "/playground", label: "Playground", icon: Sparkles },
    { to: "/students", label: "Student", icon: Users },
  ],
  parent: [
    { to: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
    { to: "/playground", label: "Playground", icon: Sparkles },
    { to: "/profile", label: "My Profile", icon: UserRound },
  ],
  student: [
    { to: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
    { to: "/playground", label: "Playground", icon: Sparkles },
    { to: "/profile", label: "My Profile", icon: UserRound },
  ],
};

const ADMIN_GROUP: NavItem[] = [{ to: "/system", label: "System", icon: Settings }];

function groupsForRole(role: Role): NavGroup[] {
  const groups: NavGroup[] = [{ label: "Workspace", items: WORKSPACE_BY_ROLE[role] ?? WORKSPACE_BY_ROLE.admin }];
  if (role === "admin") groups.push({ label: "Admin", items: ADMIN_GROUP });
  return groups;
}

export function Sidebar({ role, onNavigate }: { role: Role; onNavigate?: () => void }) {
  const groups = groupsForRole(role);
  const pathname = useRouterState({ select: (s) => s.location.pathname });

  return (
    <aside className="flex w-[220px] shrink-0 flex-col bg-sidebar">
      {/* Header */}
      <div className="flex h-12 shrink-0 items-center gap-2.5 px-4">
        <div
          className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gold-gradient font-heading text-[13px] font-extrabold leading-none text-bg shadow-card"
          aria-label="Kings Armour"
        >
          KA
        </div>
        <div className="min-w-0 leading-tight">
          <div className="font-heading text-[14px] font-bold text-fg">Armour Academy</div>
        </div>
      </div>

      {/* Nav groups */}
      <nav className="flex-1 overflow-y-auto px-2 pb-4">
        {groups.map((group, gi) => (
          <div key={group.label} className={cn(gi === 0 ? "mt-2" : "mt-5")}>
            <div className="mb-1 px-4 py-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-fg">
              {group.label}
            </div>
            <div className="space-y-0.5">
              {group.items.map((item) => {
                const active = pathname === item.to || pathname.startsWith(item.to + "/");
                const Icon = item.icon;
                return (
                  <Link
                    key={item.to}
                    to={item.to}
                    onClick={onNavigate}
                    className={cn(
                      "relative flex items-center gap-2.5 rounded-lg px-4 py-[10px] text-[14px] font-medium leading-tight transition-colors",
                      active
                        ? "bg-gold-soft text-gold"
                        : "text-muted-fg hover:bg-mut hover:text-fg",
                    )}
                  >
                    {active && (
                      <span
                        aria-hidden
                        className="absolute left-0 top-1.5 bottom-1.5 w-[3px] rounded-r bg-gold"
                        style={{ boxShadow: "0 0 12px rgba(201,169,98,0.4)" }}
                      />
                    )}
                    <Icon
                      className={cn("h-[18px] w-[18px] shrink-0", !active && "opacity-80")}
                      strokeWidth={1.6}
                    />
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </div>
          </div>
        ))}
      </nav>

      {/* Footer */}
      <div className="shrink-0 px-4 py-3">
        <button
          type="button"
          className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-[12px] font-medium text-muted-fg transition-colors hover:bg-mut hover:text-gold"
        >
          <ChatIcon className="h-[14px] w-[14px]" />
          Need help?
        </button>
        <div className="mt-1.5 px-2 text-[10px] font-medium uppercase tracking-[0.08em] text-muted-fg">
          v0.2 · Phase 2
        </div>
      </div>
    </aside>
  );
}

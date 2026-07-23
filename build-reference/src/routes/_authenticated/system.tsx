import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { cn } from "@/lib/utils";
import { SystemOverview } from "@/components/system/Overview";
import { SystemPeople } from "@/components/system/People";
import { SystemProgrammes } from "@/components/system/Programmes";
import { SystemContent } from "@/components/system/Content";
import { SystemConnections } from "@/components/system/Connections";
import { SystemActivity } from "@/components/system/Activity";
import { SystemHealth } from "@/components/system/Health";
import { SystemBilling } from "@/components/system/Billing";
import { SystemNotifications } from "@/components/system/Notifications";
import { SystemSecurity } from "@/components/system/Security";
import { SystemLanguages } from "@/components/system/Languages";
import { useAuth } from "@/lib/auth";
import { EmptyState } from "@/components/shared/EmptyState";

const TAB_IDS = [
  "overview",
  "users",
  "programmes",
  "content",
  "connections",
  "activity",
  "health",
  "billing",
  "notifications",
  "security",
  "languages",
] as const;
type TabId = (typeof TAB_IDS)[number];

export const Route = createFileRoute("/_authenticated/system")({
  head: () => ({ meta: [{ title: "System — KA Playground" }] }),
  validateSearch: (s: Record<string, unknown>): { tab: TabId } => {
    const raw = typeof s.tab === "string" ? s.tab : "overview";
    return { tab: (TAB_IDS as readonly string[]).includes(raw) ? (raw as TabId) : "overview" };
  },
  component: SystemPage,
});

type NavItem = { id: TabId; label: string; badge?: number };
type NavGroup = { label: string; items: NavItem[] };

const NAV: NavGroup[] = [
  {
    label: "Workspace",
    items: [
      { id: "overview", label: "Overview" },
      { id: "users", label: "People & Roles", badge: 24 },
      { id: "programmes", label: "Programmes", badge: 5 },
      { id: "content", label: "Content" },
    ],
  },
  {
    label: "Operations",
    items: [
      { id: "connections", label: "Connections" },
      { id: "activity", label: "Activity log" },
      { id: "health", label: "System health" },
    ],
  },
  {
    label: "Account",
    items: [
      { id: "billing", label: "Billing & usage" },
      { id: "notifications", label: "Notifications", badge: 3 },
      { id: "security", label: "Security" },
      { id: "languages", label: "Languages" },
    ],
  },
];

function SystemPage() {
  const { tab } = Route.useSearch();
  const navigate = useNavigate();
  const { user } = useAuth();

  if (user?.role !== "admin") {
    return (
      <EmptyState
        title="Admin only"
        description="System settings are restricted to administrators."
      />
    );
  }

  const setTab = (t: TabId) =>
    navigate({ to: "/system", search: { tab: t }, replace: true });

  const flatItems = NAV.flatMap((g) => g.items);

  return (
    <div className="flex flex-col gap-4 -mx-2 lg:flex-row lg:gap-6">
      {/* Mobile/tablet horizontal chip nav */}
      <nav
        className="lg:hidden -mx-2 flex gap-1.5 overflow-x-auto snap-x px-2 pb-1"
        aria-label="System sections"
      >
        {flatItems.map((item) => {
          const active = tab === item.id;
          return (
            <button
              key={item.id}
              type="button"
              onClick={() => setTab(item.id)}
              className={cn(
                "snap-start shrink-0 inline-flex items-center gap-1.5 rounded-full border text-[13px] transition-colors",
                active
                  ? "bg-gold-soft text-gold border-gold/40 font-semibold"
                  : "bg-transparent text-muted-fg border-border hover:bg-mut hover:text-fg font-medium",
              )}
              style={{ padding: "8px 14px", minHeight: 36 }}
            >
              <span>{item.label}</span>
              {item.badge !== undefined && (
                <span
                  className={cn(
                    "inline-flex items-center justify-center rounded-full text-[10px] font-semibold",
                    active ? "bg-gold text-bg" : "bg-mut text-muted-fg",
                  )}
                  style={{ minWidth: 18, padding: "0 5px", height: 14 }}
                >
                  {item.badge}
                </span>
              )}
            </button>
          );
        })}
      </nav>

      {/* Desktop left sub-nav */}
      <aside
        className="hidden lg:block sticky top-0 h-fit shrink-0 self-start"
        style={{ width: 230 }}
      >
        <nav className="flex flex-col">
          {NAV.map((group, gi) => (
            <div key={group.label} className={gi === 0 ? "" : "mt-4"}>
              <div
                className="text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-fg"
                style={{ paddingLeft: 14, marginTop: 18, marginBottom: 6 }}
              >
                {group.label}
              </div>
              <div className="flex flex-col gap-0.5">
                {group.items.map((item) => {
                  const active = tab === item.id;
                  return (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => setTab(item.id)}
                      className={cn(
                        "relative flex items-center justify-between rounded-lg text-left transition-colors",
                        active
                          ? "bg-gold-soft text-gold font-semibold"
                          : "text-muted-fg hover:bg-mut hover:text-fg font-medium",
                      )}
                      style={{ padding: "10px 14px", fontSize: 14 }}
                    >
                      {active && (
                        <span
                          aria-hidden
                          className="absolute left-0 top-2 bottom-2 w-[3px] rounded-r bg-gold"
                          style={{ boxShadow: "0 0 12px rgba(201,169,98,0.4)" }}
                        />
                      )}
                      <span>{item.label}</span>
                      {item.badge !== undefined && (
                        <span
                          className={cn(
                            "ml-2 inline-flex items-center justify-center rounded-full text-[10px] font-semibold",
                            active ? "bg-gold text-bg" : "bg-mut text-muted-fg",
                          )}
                          style={{ minWidth: 20, padding: "0 6px", height: 16 }}
                        >
                          {item.badge}
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            </div>
          ))}
        </nav>
      </aside>

      {/* Main */}
      <div className="flex-1 min-w-0 px-2 lg:px-8">
        {tab === "overview" && <SystemOverview />}
        {tab === "users" && <SystemPeople />}
        {tab === "programmes" && <SystemProgrammes />}
        {tab === "content" && <SystemContent />}
        {tab === "connections" && <SystemConnections />}
        {tab === "activity" && <SystemActivity />}
        {tab === "health" && <SystemHealth />}
        {tab === "billing" && <SystemBilling />}
        {tab === "notifications" && <SystemNotifications />}
        {tab === "security" && <SystemSecurity />}
        {tab === "languages" && <SystemLanguages />}
      </div>
    </div>
  );
}

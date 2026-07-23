import { createFileRoute } from "@tanstack/react-router";
import { lazy, Suspense } from "react";
import {
  Calendar,
  GraduationCap,
  TrendingUp,
  Users,
  Zap,
  BookOpen,
  Palette,
  Sigma,
  Car,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { KPITile } from "@/components/shared/KPITile";
import { useAuth, type Role } from "@/lib/auth";

const EnrolmentTrendChart = lazy(() =>
  import("./dashboard-charts").then((m) => ({ default: m.EnrolmentTrendChart })),
);
const CategoryPieChart = lazy(() =>
  import("./dashboard-charts").then((m) => ({ default: m.CategoryPieChart })),
);

const CATEGORY_LEGEND = [
  { name: "Language", color: "var(--cat-indigo)" },
  { name: "STEM", color: "var(--cat-purple)" },
  { name: "Arts", color: "var(--cat-pink)" },
  { name: "Maths", color: "var(--cat-orange)" },
];

export const Route = createFileRoute("/_authenticated/dashboard")({
  head: () => ({ meta: [{ title: "Dashboard — KA Playground" }] }),
  component: Dashboard,
});

const GREETING: Record<Role, string> = {
  admin: "Welcome back",
  school: "Welcome",
  teacher: "Good morning, David",
  parent: "Hello, Sarah",
  student: "Hey Tommy",
};

const SUBTITLE: Record<Role, string> = {
  admin: "Platform overview.",
  school: "Class overview.",
  teacher: "Class overview.",
  parent: "How your children are doing.",
  student: "Your learning journey.",
};

type KPI = { label: string; value: string; delta: string; fill: string; fillPercent: number };

const ADMIN_KPIS: KPI[] = [
  { label: "Total Students", value: "38", delta: "+12%", fill: "bg-gold", fillPercent: 70 },
  { label: "Active Enrolments", value: "52", delta: "+23%", fill: "bg-success", fillPercent: 80 },
  { label: "Programmes Live", value: "5", delta: "+2 new", fill: "bg-purple", fillPercent: 55 },
  { label: "Completion Rate", value: "74%", delta: "+5%", fill: "bg-pink", fillPercent: 74 },
];

type Sched = { title: string; date: string; time: string; color: string; icon: LucideIcon };

const SCHEDULE: Sched[] = [
  { title: "STEM on Car: Build Workshop #3", date: "May 22", time: "2:00 PM", color: "var(--cat-cyan)", icon: Car },
  { title: "Cambridge English: Unit 9 Live", date: "May 24", time: "10:00 AM", color: "var(--cat-indigo)", icon: BookOpen },
  { title: "Math Champions: Regional Qualifier", date: "May 28", time: "9:00 AM", color: "var(--cat-orange)", icon: Sigma },
  { title: "STEM Lab: Robotics Demo Day", date: "Jun 1", time: "1:00 PM", color: "var(--cat-purple)", icon: Zap },
  { title: "Arts Studio: Portfolio Review", date: "Jun 5", time: "3:30 PM", color: "var(--cat-pink)", icon: Palette },
];

const ChartFallback = ({ h }: { h: number }) => (
  <div className="animate-pulse rounded-md bg-mut" style={{ height: h }} />
);


function Dashboard() {
  const { user } = useAuth();
  const role = (user?.role ?? "admin") as Role;
  // TODO(P3): scope KPIs by role — parent sees their kids' enrolments,
  //   student sees their own progress. Currently shows admin/global values for all roles.
  const kpis = ADMIN_KPIS;
  const KpiIcons = [Users, GraduationCap, Zap, TrendingUp];

  return (
    <div className="space-y-5">
      <header className="space-y-1">
        <h2 className="font-heading text-display font-bold tracking-tight text-fg">
          {GREETING[role] ?? GREETING.admin}
        </h2>
        <p className="text-sm text-muted-fg">{SUBTITLE[role] ?? SUBTITLE.admin}</p>
      </header>

      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {kpis.map((kpi, i) => (
          <KPITile
            key={kpi.label}
            {...kpi}
            fillColor={kpi.fill}
            fillPercent={kpi.fillPercent}
            featured={i === 0}
            icon={KpiIcons[i]}
          />
        ))}
      </section>

      <section className="rounded-xl border border-border bg-card shadow-card">
        <header className="flex items-center justify-between px-4 py-3.5">
          <h3 className="flex items-center gap-1.5 font-heading text-sm font-bold text-fg">
            <Calendar className="h-4 w-4 text-gold" strokeWidth={1.5} />
            Upcoming Schedule
          </h3>
        </header>
        <div className="space-y-1.5 px-4 pb-4">
          {SCHEDULE.map((s) => {
            const Icon = s.icon;
            return (
              <div key={s.title} className="flex items-center gap-2.5 rounded-lg bg-mut px-3 py-2.5 transition-colors hover:bg-card-elev">
                <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: s.color }} />
                <div className="flex-1">
                  <div className="text-[13px] font-semibold text-fg">{s.title}</div>
                  <div className="text-xs text-muted-fg">{s.date} · {s.time}</div>
                </div>
                <Icon className="h-[18px] w-[18px]" strokeWidth={1.5} style={{ color: s.color }} />
              </div>
            );
          })}
        </div>
      </section>

      <section className="grid grid-cols-1 gap-3 md:grid-cols-[2fr_1fr]">
        <div className="rounded-xl border border-border bg-card shadow-card">
          <header className="flex items-center justify-between px-4 py-3.5">
            <h3 className="font-heading text-sm font-bold text-fg">Enrolment Growth</h3>
            <span className="rounded bg-gold-soft px-1.5 py-0.5 text-[10px] font-semibold text-gold">2026</span>
          </header>
          <div className="px-2 pb-4">
            <Suspense fallback={<ChartFallback h={240} />}>
              <EnrolmentTrendChart />
            </Suspense>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card shadow-card">
          <header className="flex items-center justify-between px-4 py-3.5">
            <h3 className="font-heading text-sm font-bold text-fg">By Category</h3>
          </header>
          <div className="relative px-2 pb-3">
            <Suspense fallback={<ChartFallback h={200} />}>
              <CategoryPieChart />
            </Suspense>
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center pb-3">
              <div className="text-center">
                <div className="font-heading text-2xl font-bold text-fg leading-none">100</div>
                <div className="mt-0.5 text-[9px] uppercase tracking-wider text-muted-fg">total</div>
              </div>
            </div>
            <div className="mt-2 flex flex-wrap justify-center gap-x-3 gap-y-1 px-2 pb-2">
              {CATEGORY_LEGEND.map((c) => (
                <span key={c.name} className="inline-flex items-center gap-1.5 text-[10px] font-medium text-muted-fg">
                  <span className="h-1.5 w-1.5 rounded-full" style={{ background: c.color }} />
                  {c.name}
                </span>
              ))}
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}

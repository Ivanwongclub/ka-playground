import { BarChart, Bar, XAxis, ResponsiveContainer, Cell } from "recharts";

type Tile = { label: string; value: string; sub: string; tone?: "ok" | "warn" };

const TILES: Tile[] = [
  { label: "Platform uptime", value: "99.97%", sub: "Last 30 days" },
  { label: "Database", value: "Healthy", sub: "12 ms avg query time" },
  { label: "File storage", value: "Healthy", sub: "Last write 8 sec ago" },
  { label: "Email delivery", value: "Healthy", sub: "All recent emails delivered" },
  { label: "External connections", value: "Healthy", sub: "5 / 5 programmes online" },
  { label: "Background jobs", value: "Healthy", sub: "Last sync 3 min ago" },
];

const USAGE = [
  { d: "Mon", v: 84 },
  { d: "Tue", v: 92 },
  { d: "Wed", v: 110 },
  { d: "Thu", v: 124 },
  { d: "Fri", v: 138 },
  { d: "Sat", v: 76 },
  { d: "Sun", v: 64 },
];

export function SystemHealth() {
  return (
    <div className="flex flex-col gap-6 pb-8">
      <header className="flex items-end justify-between gap-4">
        <div>
          <h2 className="font-heading text-2xl font-bold text-fg">System health</h2>
          <p className="mt-1 text-sm text-muted-fg">Live status of every subsystem powering your workspace.</p>
        </div>
        <span className="inline-flex items-center gap-2 rounded-full bg-success/15 px-3 py-1.5 text-[12px] font-semibold text-success">
          <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-success" />
          All systems operational
        </span>
      </header>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {TILES.map((t) => (
          <div key={t.label} className="rounded-[14px] border border-border bg-card p-4">
            <div className="flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-success" />
              <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-fg">{t.label}</span>
            </div>
            <div className="mt-2 font-heading text-2xl font-bold text-fg">{t.value}</div>
            <div className="mt-0.5 text-[12px] text-muted-fg">{t.sub}</div>
          </div>
        ))}
      </div>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <h3 className="font-heading text-[15px] font-bold text-fg">7-day active sessions</h3>
        <p className="mt-0.5 text-[12px] text-muted-fg">Daily unique active users across all roles.</p>
        <div className="mt-4 h-[200px]">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={USAGE}>
              <defs>
                <linearGradient id="goldBar" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="hsl(43 60% 65%)" />
                  <stop offset="100%" stopColor="hsl(38 50% 45%)" />
                </linearGradient>
              </defs>
              <XAxis
                dataKey="d"
                tick={{ fill: "var(--muted-foreground, #888)", fontSize: 11 }}
                axisLine={false}
                tickLine={false}
              />
              <Bar dataKey="v" radius={[6, 6, 0, 0]}>
                {USAGE.map((_, i) => (
                  <Cell key={i} fill="url(#goldBar)" />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  );
}

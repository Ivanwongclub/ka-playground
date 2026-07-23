import {
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

const TREND = [
  { month: "Jan", enrolments: 12 },
  { month: "Feb", enrolments: 18 },
  { month: "Mar", enrolments: 24 },
  { month: "Apr", enrolments: 31 },
  { month: "May", enrolments: 38 },
];

const CATEGORY = [
  { name: "Language", value: 28, color: "var(--cat-indigo)" },
  { name: "STEM", value: 40, color: "var(--cat-purple)" },
  { name: "Arts", value: 18, color: "var(--cat-pink)" },
  { name: "Maths", value: 14, color: "var(--cat-orange)" },
];

function ChartTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean;
  payload?: Array<{ name: string; value: number; color?: string }>;
  label?: string;
}) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-md border border-border bg-card px-2.5 py-1.5 text-xs shadow-card">
      {label && <div className="mb-1 font-semibold text-fg">{label}</div>}
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2 text-muted-fg">
          {p.color && <span className="h-2 w-2 rounded-full" style={{ background: p.color }} />}
          <span>{p.name}: </span>
          <span className="font-semibold text-fg">{p.value}</span>
        </div>
      ))}
    </div>
  );
}

export function EnrolmentTrendChart() {
  return (
    <ResponsiveContainer width="100%" height={240}>
      <LineChart data={TREND} margin={{ top: 8, right: 16, bottom: 8, left: 0 }}>
        <defs>
          <linearGradient id="goldStroke" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor="var(--gold)" />
            <stop offset="100%" stopColor="var(--gold-2)" />
          </linearGradient>
        </defs>
        <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="month" stroke="var(--muted-fg)" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
        <YAxis stroke="var(--muted-fg)" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} width={28} />
        <Tooltip content={<ChartTooltip />} cursor={{ stroke: "var(--gold)", strokeOpacity: 0.2 }} />
        <Line
          type="monotone"
          dataKey="enrolments"
          stroke="url(#goldStroke)"
          strokeWidth={2.5}
          dot={{ r: 4, fill: "var(--gold)", strokeWidth: 0 }}
          activeDot={{ r: 6, fill: "var(--gold)" }}
        />
      </LineChart>
    </ResponsiveContainer>
  );
}

export function CategoryPieChart() {
  return (
    <ResponsiveContainer width="100%" height={200}>
      <PieChart>
        <Tooltip content={<ChartTooltip />} />
        <Pie
          data={CATEGORY}
          dataKey="value"
          nameKey="name"
          cx="50%"
          cy="50%"
          innerRadius={48}
          outerRadius={78}
          paddingAngle={2}
          stroke="none"
        >
          {CATEGORY.map((c) => (
            <Cell key={c.name} fill={c.color} />
          ))}
        </Pie>
      </PieChart>
    </ResponsiveContainer>
  );
}

export const CATEGORY_LEGEND = CATEGORY;

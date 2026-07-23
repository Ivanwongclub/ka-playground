import { KPITile } from "@/components/shared/KPITile";
import { UserPlus, BookOpen, FileEdit, Bell, ShieldCheck } from "lucide-react";

const ACTIVITY = [
  {
    icon: UserPlus,
    color: "text-success",
    bg: "bg-success/15",
    text: "Sarah Chan enrolled Emily Wong into Cambridge English Online",
    time: "2 minutes ago",
  },
  {
    icon: BookOpen,
    color: "text-purple",
    bg: "bg-purple/15",
    text: "Tommy Chan completed Module 4 of STEM on Car",
    time: "18 minutes ago",
  },
  {
    icon: FileEdit,
    color: "text-gold",
    bg: "bg-gold-soft",
    text: "Alex Admin updated the Cambridge English Online landing copy",
    time: "1 hour ago",
  },
  {
    icon: Bell,
    color: "text-orange",
    bg: "bg-orange/15",
    text: "Programme 'Young Inventors Lab' is launching in 14 days",
    time: "3 hours ago",
  },
  {
    icon: ShieldCheck,
    color: "text-cyan",
    bg: "bg-cyan/15",
    text: "Sandra School invited 3 new teachers to the workspace",
    time: "Yesterday",
  },
];

export function SystemOverview() {
  return (
    <div className="flex flex-col gap-6 pb-8">
      <header>
        <h2 className="font-heading text-h2-fluid font-bold text-fg">Overview</h2>
        <p className="mt-1 text-sm text-muted-fg">
          A bird's-eye view of your workspace today.
        </p>
      </header>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KPITile label="Active users" value="24" delta="+3 this month" deltaTone="up" fillPercent={68} featured />
        <KPITile label="Active programmes" value="5" delta="1 launching soon" deltaTone="flat" fillPercent={50} />
        <KPITile label="Storage used" value="2.1 GB" delta="of 50 GB plan" deltaTone="flat" fillPercent={4} />
        <KPITile label="Monthly active" value="112" delta="+18% vs last month" deltaTone="up" fillPercent={78} />
      </div>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <div className="flex items-end justify-between gap-4">
          <div>
            <h3 className="font-heading text-[15px] font-bold text-fg">Recent activity</h3>
            <p className="mt-0.5 text-[12px] text-muted-fg">Last actions across the workspace</p>
          </div>
          <button className="text-[12px] font-semibold text-gold hover:underline">View all →</button>
        </div>

        <ul className="mt-4 flex flex-col">
          {ACTIVITY.map((a, i) => {
            const Icon = a.icon;
            return (
              <li
                key={i}
                className="flex items-start gap-3 border-t border-border first:border-t-0"
                style={{ padding: "12px 0" }}
              >
                <span
                  className={`grid h-8 w-8 shrink-0 place-items-center rounded-full ${a.bg} ${a.color}`}
                >
                  <Icon size={14} />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="text-[13px] text-fg">{a.text}</div>
                  <div className="mt-0.5 text-[11px] text-muted-fg">{a.time}</div>
                </div>
              </li>
            );
          })}
        </ul>
      </div>
    </div>
  );
}

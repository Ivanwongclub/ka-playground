import { toast } from "sonner";
import { Plus, MoreHorizontal, Pencil } from "lucide-react";
import { cn } from "@/lib/utils";

type Person = {
  name: string;
  email: string;
  role: "Admin" | "School" | "Teacher" | "Parent" | "Student";
  online: boolean;
  lastActive: string;
};

const PEOPLE: Person[] = [
  { name: "Alex Admin", email: "admin@ka.test", role: "Admin", online: true, lastActive: "Online now" },
  { name: "Sandra School", email: "school@ka.test", role: "School", online: true, lastActive: "Online now" },
  { name: "David Li", email: "teacher@ka.test", role: "Teacher", online: false, lastActive: "12 min ago" },
  { name: "Margaret Lau", email: "margaret.lau@ka.test", role: "Teacher", online: true, lastActive: "Online now" },
  { name: "Sarah Chan", email: "parent@ka.test", role: "Parent", online: false, lastActive: "1 hour ago" },
  { name: "Jenny Wong", email: "jenny.wong@ka.test", role: "Parent", online: false, lastActive: "3 hours ago" },
  { name: "Tommy Chan", email: "student@ka.test", role: "Student", online: true, lastActive: "Online now" },
  { name: "Emily Wong", email: "emily.wong@ka.test", role: "Student", online: false, lastActive: "Yesterday" },
  { name: "Marcus Lee", email: "marcus.lee@ka.test", role: "Student", online: false, lastActive: "2 days ago" },
  { name: "Olivia Tang", email: "olivia.tang@ka.test", role: "Student", online: false, lastActive: "4 days ago" },
];

const ROLE_STYLES: Record<Person["role"], string> = {
  Admin: "bg-gold-soft text-gold",
  School: "bg-cyan/15 text-cyan",
  Teacher: "bg-purple/15 text-purple",
  Parent: "bg-pink/15 text-pink",
  Student: "bg-indigo/15 text-indigo",
};

function initials(name: string) {
  return name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();
}

const PERMS = ["View programmes", "Enrol students", "Edit notes", "Manage users", "Workspace settings"];
const MATRIX: Record<Person["role"], (true | false | "partial")[]> = {
  Admin: [true, true, true, true, true],
  School: [true, true, true, "partial", false],
  Teacher: [true, false, true, false, false],
  Parent: [true, "partial", false, false, false],
  Student: [true, false, false, false, false],
};

function Cell({ v }: { v: true | false | "partial" }) {
  if (v === true) return <span className="text-success">✓</span>;
  if (v === "partial") return <span className="text-orange text-[11px] font-semibold">Partial</span>;
  return <span className="text-muted-fg">—</span>;
}

export function SystemPeople() {
  const stats = [
    { label: "Total people", value: 24 },
    { label: "Administrators", value: 2 },
    { label: "Teachers", value: 8 },
    { label: "Pending invites", value: 3 },
  ];

  return (
    <div className="flex flex-col gap-6 pb-8">
      <header className="flex items-end justify-between gap-4">
        <div>
          <h2 className="font-heading text-2xl font-bold text-fg">People & Roles</h2>
          <p className="mt-1 text-sm text-muted-fg">Everyone in your workspace and what they can do.</p>
        </div>
        <button
          onClick={() => toast("Invite flow coming soon")}
          className="inline-flex items-center gap-1.5 rounded-full bg-gold px-3.5 py-2 text-[12px] font-semibold text-bg hover:bg-gold/90"
        >
          <Plus size={14} /> Invite people
        </button>
      </header>

      <div className="flex flex-wrap gap-2">
        {stats.map((s) => (
          <div
            key={s.label}
            className="inline-flex items-center gap-2 rounded-full bg-card border border-border px-3.5 py-1.5 text-[12px] font-semibold text-fg"
          >
            <span className="text-muted-fg">{s.label}</span>
            <span className="text-gold">{s.value}</span>
          </div>
        ))}
      </div>

      {/* Users table */}
      <div className="overflow-hidden rounded-[14px] border border-border bg-card">
        <table className="w-full text-left text-[13px]">
          <thead className="bg-muted">
            <tr className="text-[11px] uppercase tracking-wider text-muted-fg">
              <th className="px-4 py-3 font-semibold">Person</th>
              <th className="px-4 py-3 font-semibold">Email</th>
              <th className="px-4 py-3 font-semibold">Role</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 font-semibold">Last active</th>
              <th className="px-4 py-3 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {PEOPLE.map((p) => (
              <tr key={p.email} className="border-t border-border hover:bg-mut/50">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2.5">
                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gold-gradient text-[10px] font-bold text-bg">
                      {initials(p.name)}
                    </span>
                    <span className="font-medium text-fg">{p.name}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-muted-fg">{p.email}</td>
                <td className="px-4 py-3">
                  <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold", ROLE_STYLES[p.role])}>
                    {p.role}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    <span className={cn("h-2 w-2 rounded-full", p.online ? "bg-success" : "bg-muted-fg/40")} />
                    <span className="text-[12px] text-muted-fg">{p.online ? "Online" : "Offline"}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-muted-fg">{p.lastActive}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center justify-end gap-1">
                    <button
                      className="grid h-7 w-7 place-items-center rounded-md text-muted-fg hover:bg-mut hover:text-fg"
                      onClick={() => toast(`Edit ${p.name} — coming soon`)}
                      aria-label={`Edit ${p.name}`}
                    >
                      <Pencil size={13} />
                    </button>
                    <button
                      className="grid h-7 w-7 place-items-center rounded-md text-muted-fg hover:bg-mut hover:text-fg"
                      aria-label="More"
                    >
                      <MoreHorizontal size={14} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Permission matrix */}
      <div className="overflow-hidden rounded-[14px] border border-border bg-card">
        <div className="border-b border-border px-5 py-4">
          <h3 className="font-heading text-[15px] font-bold text-fg">Permissions</h3>
          <p className="mt-0.5 text-[12px] text-muted-fg">What each role can do across the workspace.</p>
        </div>
        <table className="w-full text-left text-[13px]">
          <thead className="bg-muted">
            <tr className="text-[11px] uppercase tracking-wider text-muted-fg">
              <th className="px-4 py-3 font-semibold">Role</th>
              {PERMS.map((p) => (
                <th key={p} className="px-4 py-3 font-semibold text-center">{p}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(Object.keys(MATRIX) as Person["role"][]).map((role) => (
              <tr key={role} className="border-t border-border">
                <td className="px-4 py-3 font-semibold text-fg">{role}</td>
                {MATRIX[role].map((v, i) => (
                  <td key={i} className="px-4 py-3 text-center text-[14px]">
                    <Cell v={v} />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

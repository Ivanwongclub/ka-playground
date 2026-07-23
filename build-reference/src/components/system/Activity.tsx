import { useState } from "react";
import { toast } from "sonner";
import { Download, UserPlus, FileEdit, Bell, ShieldCheck, BookOpen, LogIn, LogOut } from "lucide-react";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

type Entry = {
  icon: typeof UserPlus;
  color: string;
  bg: string;
  type: "enrolment" | "user" | "system";
  text: string;
  time: string;
  ip: string;
};

const LOG: Entry[] = [
  { icon: UserPlus, color: "text-success", bg: "bg-success/15", type: "enrolment", text: "Sarah Chan enrolled Emily Wong into Cambridge English Online", time: "2 minutes ago", ip: "203.0.113.42" },
  { icon: BookOpen, color: "text-purple", bg: "bg-purple/15", type: "system", text: "Tommy Chan completed Module 4 of STEM on Car", time: "18 minutes ago", ip: "198.51.100.7" },
  { icon: FileEdit, color: "text-gold", bg: "bg-gold-soft", type: "user", text: "Alex Admin updated the Cambridge English landing copy", time: "1 hour ago", ip: "203.0.113.10" },
  { icon: LogIn, color: "text-cyan", bg: "bg-cyan/15", type: "user", text: "David Li signed in", time: "1 hour ago", ip: "203.0.113.88" },
  { icon: Bell, color: "text-orange", bg: "bg-orange/15", type: "system", text: "Programme 'Young Inventors Lab' is launching in 14 days", time: "3 hours ago", ip: "—" },
  { icon: ShieldCheck, color: "text-cyan", bg: "bg-cyan/15", type: "user", text: "Sandra School invited 3 new teachers to the workspace", time: "Yesterday", ip: "203.0.113.10" },
  { icon: UserPlus, color: "text-success", bg: "bg-success/15", type: "enrolment", text: "Sarah Chan enrolled Tommy Chan into Maths Mastery", time: "Yesterday", ip: "203.0.113.42" },
  { icon: LogOut, color: "text-muted-fg", bg: "bg-mut", type: "user", text: "Margaret Lau signed out", time: "Yesterday", ip: "198.51.100.4" },
  { icon: FileEdit, color: "text-gold", bg: "bg-gold-soft", type: "user", text: "Alex Admin updated workspace branding", time: "2 days ago", ip: "203.0.113.10" },
  { icon: UserPlus, color: "text-success", bg: "bg-success/15", type: "enrolment", text: "David Li enrolled Marcus Lee into STEM on Car", time: "2 days ago", ip: "203.0.113.88" },
  { icon: ShieldCheck, color: "text-cyan", bg: "bg-cyan/15", type: "system", text: "Daily backup completed successfully", time: "3 days ago", ip: "—" },
  { icon: Bell, color: "text-orange", bg: "bg-orange/15", type: "system", text: "Storage usage crossed 2 GB", time: "4 days ago", ip: "—" },
];

const FILTERS = [
  { value: "all", label: "All activity" },
  { value: "enrolment", label: "Enrolments only" },
  { value: "user", label: "User changes" },
  { value: "system", label: "System events" },
];

export function SystemActivity() {
  const [filter, setFilter] = useState<string>("all");
  const [q, setQ] = useState("");

  const filtered = LOG.filter(
    (e) =>
      (filter === "all" || e.type === filter) &&
      (q.trim() === "" || e.text.toLowerCase().includes(q.toLowerCase())),
  );

  return (
    <div className="flex flex-col gap-5 pb-8">
      <header className="flex items-end justify-between gap-4">
        <div>
          <h2 className="font-heading text-2xl font-bold text-fg">Activity log</h2>
          <p className="mt-1 text-sm text-muted-fg">A timestamped audit trail across the workspace.</p>
        </div>
        <button
          onClick={() => toast("Export started — you'll get an email when it's ready.")}
          className="inline-flex items-center gap-1.5 rounded-full border border-border px-3.5 py-2 text-[12px] font-semibold text-fg hover:bg-mut"
        >
          <Download size={13} /> Export
        </button>
      </header>

      <div className="flex flex-wrap items-center gap-2">
        <Select value={filter} onValueChange={setFilter}>
          <SelectTrigger className="w-[200px]"><SelectValue /></SelectTrigger>
          <SelectContent>
            {FILTERS.map((f) => <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>)}
          </SelectContent>
        </Select>
        <Input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search activity..."
          className="max-w-sm"
        />
      </div>

      <div className="rounded-[14px] border border-border bg-card">
        <ul>
          {filtered.length === 0 && (
            <li className="px-5 py-10 text-center text-[13px] text-muted-fg">No activity matches your filters.</li>
          )}
          {filtered.map((e, i) => {
            const Icon = e.icon;
            return (
              <li key={i} className="flex items-start gap-3 border-t border-border first:border-t-0 px-5 py-3.5">
                <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-full ${e.bg} ${e.color}`}>
                  <Icon size={14} />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="text-[13px] text-fg">{e.text}</div>
                  <div className="mt-0.5 text-[11px] text-muted-fg">{e.time} · IP {e.ip}</div>
                </div>
              </li>
            );
          })}
        </ul>
      </div>

      <div className="flex justify-center">
        <button
          onClick={() => toast("End of mock log.")}
          className="rounded-full border border-border px-4 py-2 text-[12px] font-semibold text-fg hover:bg-mut"
        >
          Load more
        </button>
      </div>
    </div>
  );
}

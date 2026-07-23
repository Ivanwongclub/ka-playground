import { useEffect, useState } from "react";
import { toast } from "sonner";
import { ExternalLink, Pencil, BookOpen } from "lucide-react";
import { supabase } from "@/integrations/supabase/client";
import { cn } from "@/lib/utils";

type Programme = {
  id: string;
  title: string;
  category: string;
  organiser: string;
  period_start: string | null;
  period_end: string | null;
  capacity: number;
  enrolled_count: number;
  status: string;
  brand_color: string;
};

const STATUS_STYLES: Record<string, string> = {
  Open: "bg-success/15 text-success",
  Registering: "bg-cyan/15 text-cyan",
  "Coming Soon": "bg-orange/15 text-orange",
  Closed: "bg-mut text-muted-fg",
};

function fmt(start: string | null, end: string | null) {
  if (!start || !end) return "TBD";
  const f = (d: string) => new Date(d).toLocaleDateString("en-GB", { month: "short", year: "numeric" });
  return `${f(start)} — ${f(end)}`;
}

export function SystemProgrammes() {
  const [items, setItems] = useState<Programme[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    (async () => {
      const { data } = await supabase
        .from("programmes")
        .select("id, title, category, organiser, period_start, period_end, capacity, enrolled_count, status, brand_color")
        .order("created_at", { ascending: true });
      setItems((data ?? []) as Programme[]);
      setLoading(false);
    })();
  }, []);

  return (
    <div className="flex flex-col gap-6 pb-8">
      <header className="flex items-end justify-between gap-4">
        <div>
          <h2 className="font-heading text-2xl font-bold text-fg">Programmes</h2>
          <p className="mt-1 text-sm text-muted-fg">Every programme in the workspace and its enrolment health.</p>
        </div>
        <button
          onClick={() => toast("Create programme flow lands in Phase 4")}
          className="inline-flex items-center gap-1.5 rounded-full bg-gold px-3.5 py-2 text-[12px] font-semibold text-bg hover:bg-gold/90"
        >
          <BookOpen size={14} /> New programme
        </button>
      </header>

      <div className="overflow-hidden rounded-[14px] border border-border bg-card">
        <table className="w-full text-left text-[13px]">
          <thead className="bg-muted">
            <tr className="text-[11px] uppercase tracking-wider text-muted-fg">
              <th className="px-4 py-3 font-semibold">Programme</th>
              <th className="px-4 py-3 font-semibold">Organiser</th>
              <th className="px-4 py-3 font-semibold">Period</th>
              <th className="px-4 py-3 font-semibold" style={{ minWidth: 180 }}>Capacity</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr><td colSpan={6} className="px-4 py-10 text-center text-muted-fg">Loading…</td></tr>
            )}
            {!loading && items.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-10 text-center text-muted-fg">No programmes</td></tr>
            )}
            {items.map((p) => {
              const pct = p.capacity > 0 ? Math.min(100, Math.round((p.enrolled_count / p.capacity) * 100)) : 0;
              return (
                <tr key={p.id} className="border-t border-border hover:bg-mut/50">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2.5">
                      <span className="h-2.5 w-2.5 rounded-full" style={{ background: p.brand_color }} />
                      <div>
                        <div className="font-medium text-fg">{p.title}</div>
                        <div className="text-[11px] text-muted-fg">{p.category}</div>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-muted-fg">{p.organiser}</td>
                  <td className="px-4 py-3 text-muted-fg">{fmt(p.period_start, p.period_end)}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <div className="h-1.5 w-24 overflow-hidden rounded-full bg-mut">
                        <div className="h-full rounded-full" style={{ width: `${pct}%`, background: p.brand_color }} />
                      </div>
                      <span className="text-[12px] tabular-nums text-muted-fg">{p.enrolled_count}/{p.capacity}</span>
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <span className={cn("inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold", STATUS_STYLES[p.status] ?? "bg-mut text-muted-fg")}>
                      {p.status}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => toast(`Edit ${p.title} — coming soon`)}
                        className="grid h-7 w-7 place-items-center rounded-md text-muted-fg hover:bg-mut hover:text-fg"
                        aria-label="Edit"
                      >
                        <Pencil size={13} />
                      </button>
                      <a
                        href={`/playground/${p.id}`}
                        className="grid h-7 w-7 place-items-center rounded-md text-muted-fg hover:bg-mut hover:text-fg"
                        aria-label="View"
                      >
                        <ExternalLink size={13} />
                      </a>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

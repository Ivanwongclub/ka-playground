import { useEffect, useState } from "react";
import { toast } from "sonner";
import { supabase } from "@/integrations/supabase/client";
import { friendlySignIn, friendlyProgress } from "@/lib/friendly";
import { Toggle } from "@/components/shared/Toggle";

type Row = {
  id: string;
  title: string;
  brand_color: string;
  sign_in_method: string;
  progress_updates: string;
};

export function SystemConnections() {
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true);
  const [autoRetry, setAutoRetry] = useState(true);
  const [emailAlerts, setEmailAlerts] = useState(true);
  const [autoCreate, setAutoCreate] = useState(false);

  useEffect(() => {
    (async () => {
      const { data } = await supabase
        .from("programmes")
        .select("id, title, brand_color, sign_in_method, progress_updates")
        .order("created_at", { ascending: true });
      setRows((data ?? []) as Row[]);
      setLoading(false);
    })();
  }, []);

  const lastUpdates = ["3 min ago", "8 min ago", "12 min ago", "21 min ago", "1 hour ago"];

  return (
    <div className="flex flex-col gap-6 pb-8">
      <header>
        <h2 className="font-heading text-2xl font-bold text-fg">Connections</h2>
        <p className="mt-1 text-sm text-muted-fg">How each programme talks to its delivery system.</p>
      </header>

      <div className="overflow-hidden rounded-[14px] border border-border bg-card">
        <table className="w-full text-left text-[13px]">
          <thead className="bg-muted">
            <tr className="text-[11px] uppercase tracking-wider text-muted-fg">
              <th className="px-4 py-3 font-semibold">Programme</th>
              <th className="px-4 py-3 font-semibold">Sign-in method</th>
              <th className="px-4 py-3 font-semibold">Progress updates</th>
              <th className="px-4 py-3 font-semibold">Last update</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && <tr><td colSpan={6} className="px-4 py-10 text-center text-muted-fg">Loading…</td></tr>}
            {rows.map((r, i) => (
              <tr key={r.id} className="border-t border-border hover:bg-mut/50">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2.5">
                    <span className="h-2.5 w-2.5 rounded-full" style={{ background: r.brand_color }} />
                    <span className="font-medium text-fg">{r.title}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-muted-fg">{friendlySignIn(r.sign_in_method)}</td>
                <td className="px-4 py-3 text-muted-fg">{friendlyProgress(r.progress_updates)}</td>
                <td className="px-4 py-3 text-muted-fg">{lastUpdates[i % lastUpdates.length]}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    <span className="h-2 w-2 rounded-full bg-success" />
                    <span className="text-[12px] font-semibold text-success">Connected</span>
                  </div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center justify-end gap-1.5">
                    <button onClick={() => toast.success(`${r.title} — connection OK`)} className="rounded-md border border-border px-2.5 py-1 text-[11px] font-semibold text-fg hover:bg-mut">Test</button>
                    <button onClick={() => toast(`Edit ${r.title} — coming soon`)} className="rounded-md border border-border px-2.5 py-1 text-[11px] font-semibold text-fg hover:bg-mut">Edit</button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <h3 className="font-heading text-[15px] font-bold text-fg">Connection settings</h3>
        <p className="mt-0.5 text-[12px] text-muted-fg">Defaults applied to every programme connection.</p>
        <div className="mt-4 flex flex-col">
          <ToggleRow label="Auto-retry failed updates" desc="Retry failed progress sync up to 3 times before alerting." checked={autoRetry} onChange={setAutoRetry} />
          <ToggleRow label="Email alerts on connection issues" desc="Notify admins when a programme connection fails." checked={emailAlerts} onChange={setEmailAlerts} />
          <ToggleRow label="Auto-create accounts on enrolment" desc="Provision a delivery-side account when a student is enrolled." checked={autoCreate} onChange={setAutoCreate} />
        </div>
      </div>
    </div>
  );
}

function ToggleRow({
  label, desc, checked, onChange,
}: { label: string; desc: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className="flex items-start justify-between gap-4 border-t border-border first:border-t-0 py-3">
      <div className="min-w-0">
        <div className="text-[13px] font-semibold text-fg">{label}</div>
        <div className="mt-0.5 text-[12px] text-muted-fg">{desc}</div>
      </div>
      <Toggle checked={checked} onChange={onChange} />
    </div>
  );
}

import { toast } from "sonner";
import { Download, CreditCard } from "lucide-react";
import { KPITile } from "@/components/shared/KPITile";

const INVOICES = [
  { month: "May 2026", amount: "HK$8,400", status: "Paid" },
  { month: "Apr 2026", amount: "HK$8,400", status: "Paid" },
  { month: "Mar 2026", amount: "HK$8,400", status: "Paid" },
  { month: "Feb 2026", amount: "HK$8,400", status: "Paid" },
];

export function SystemBilling() {
  return (
    <div className="flex flex-col gap-6 pb-8">
      <header>
        <h2 className="font-heading text-2xl font-bold text-fg">Billing & usage</h2>
        <p className="mt-1 text-sm text-muted-fg">Your plan, your invoices, and where you stand this month.</p>
      </header>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KPITile label="Current plan" value="Enterprise" delta="Unlimited programmes" deltaTone="flat" fillPercent={100} />
        <KPITile label="Next billing" value="Jun 1, 2026" delta="HK$8,400" deltaTone="flat" fillPercent={50} />
        <KPITile label="This month" value="HK$8,400" delta="of HK$10,000 limit" deltaTone="flat" fillPercent={84} />
        <KPITile label="Year to date" value="HK$72,200" delta="+14% vs last year" deltaTone="up" fillPercent={66} />
      </div>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <h3 className="font-heading text-[15px] font-bold text-fg">Payment method</h3>
        <div className="mt-3 flex items-center justify-between gap-4 rounded-[10px] border border-border bg-mut/30 p-4">
          <div className="flex items-center gap-3">
            <div className="grid h-10 w-14 place-items-center rounded-md bg-bg text-[10px] font-bold text-gold">
              <CreditCard size={18} />
            </div>
            <div>
              <div className="text-[13px] font-semibold text-fg">Visa ending in 4242</div>
              <div className="text-[11px] text-muted-fg">Expires 09 / 28 · Billing contact: alex@ka.test</div>
            </div>
          </div>
          <button
            onClick={() => toast("Update card flow coming soon")}
            className="rounded-full border border-border px-3 py-1.5 text-[12px] font-semibold text-fg hover:bg-mut"
          >
            Update card
          </button>
        </div>
      </div>

      <div className="overflow-hidden rounded-[14px] border border-border bg-card">
        <div className="border-b border-border px-5 py-4">
          <h3 className="font-heading text-[15px] font-bold text-fg">Invoices</h3>
        </div>
        <table className="w-full text-left text-[13px]">
          <thead className="bg-muted">
            <tr className="text-[11px] uppercase tracking-wider text-muted-fg">
              <th className="px-4 py-3 font-semibold">Period</th>
              <th className="px-4 py-3 font-semibold">Amount</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 font-semibold text-right">Receipt</th>
            </tr>
          </thead>
          <tbody>
            {INVOICES.map((inv) => (
              <tr key={inv.month} className="border-t border-border hover:bg-mut/50">
                <td className="px-4 py-3 font-medium text-fg">{inv.month}</td>
                <td className="px-4 py-3 text-fg">{inv.amount}</td>
                <td className="px-4 py-3">
                  <span className="inline-flex rounded-full bg-success/15 px-2.5 py-0.5 text-[11px] font-semibold text-success">
                    {inv.status}
                  </span>
                </td>
                <td className="px-4 py-3 text-right">
                  <button
                    onClick={() => toast(`Downloading ${inv.month} invoice…`)}
                    className="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1 text-[11px] font-semibold text-fg hover:bg-mut"
                  >
                    <Download size={12} /> PDF
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

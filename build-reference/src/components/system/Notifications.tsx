import { useState } from "react";
import { Toggle } from "@/components/shared/Toggle";

type Item = { id: string; label: string; desc: string; defaultOn: boolean };

const ADMIN_NOTIFS: Item[] = [
  { id: "a1", label: "New enrolment created", desc: "Email + in-app when any student is enrolled.", defaultOn: true },
  { id: "a2", label: "Programme capacity reached", desc: "Alert when a programme hits 100% capacity.", defaultOn: true },
  { id: "a3", label: "Connection failures", desc: "Notify when a programme connection fails twice in 1 hour.", defaultOn: true },
  { id: "a4", label: "New invite accepted", desc: "Tell admins when an invited user signs in.", defaultOn: false },
  { id: "a5", label: "Weekly workspace digest", desc: "Monday morning summary of activity, enrolments, and billing.", defaultOn: true },
  { id: "a6", label: "Billing reminders", desc: "Warn 7 days before the next charge.", defaultOn: true },
];

const PARENT_NOTIFS: Item[] = [
  { id: "p1", label: "Enrolment confirmation", desc: "Confirmation email when your child is enrolled.", defaultOn: true },
  { id: "p2", label: "New programme available", desc: "Recommendations based on your child's level.", defaultOn: false },
  { id: "p3", label: "Quiz score below threshold", desc: "Alert if a score falls under 60%.", defaultOn: true },
  { id: "p4", label: "Weekly progress summary", desc: "Friday digest of modules completed and grades.", defaultOn: true },
  { id: "p5", label: "Teacher notes added", desc: "Email when a teacher adds a note to your child.", defaultOn: true },
];

export function SystemNotifications() {
  return (
    <div className="flex flex-col gap-6 pb-8">
      <header>
        <h2 className="font-heading text-2xl font-bold text-fg">Notifications</h2>
        <p className="mt-1 text-sm text-muted-fg">Pick which events trigger emails and in-app alerts.</p>
      </header>

      <Card title="For administrators" subtitle="Operational events admins should know about.">
        {ADMIN_NOTIFS.map((n) => <ToggleRow key={n.id} item={n} />)}
      </Card>
      <Card title="For parents and students" subtitle="What families and students receive about their programmes.">
        {PARENT_NOTIFS.map((n) => <ToggleRow key={n.id} item={n} />)}
      </Card>
    </div>
  );
}

function Card({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <div className="rounded-[14px] border border-border bg-card p-5">
      <h3 className="font-heading text-[15px] font-bold text-fg">{title}</h3>
      {subtitle && <p className="mt-0.5 text-[12px] text-muted-fg">{subtitle}</p>}
      <div className="mt-3 flex flex-col">{children}</div>
    </div>
  );
}

function ToggleRow({ item }: { item: Item }) {
  const [on, setOn] = useState(item.defaultOn);
  return (
    <div className="flex items-start justify-between gap-4 border-t border-border first:border-t-0 py-3">
      <div className="min-w-0">
        <div className="text-[13px] font-semibold text-fg">{item.label}</div>
        <div className="mt-0.5 text-[12px] text-muted-fg">{item.desc}</div>
      </div>
      <Toggle checked={on} onChange={setOn} />
    </div>
  );
}

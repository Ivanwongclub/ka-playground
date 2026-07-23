import { useEffect, useState } from "react";
import { Link } from "@tanstack/react-router";
import { toast } from "sonner";
import { Plus, X } from "lucide-react";
import { supabase } from "@/integrations/supabase/client";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

type Programme = { id: string; title: string };
type Announcement = { id?: string; title: string; body: string; programme_id?: string; color?: string };
type Stat = { v: string; l: string };

type Cms = {
  hero_title: string;
  hero_subtitle: string;
  hero_cta: string;
  featured_eyebrow: string;
  featured_cta: string;
  featured_programme_id: string | null;
  announcements: Announcement[];
  announcements_title: string;
  stats: Stat[];
};

function emptyCms(): Cms {
  return {
    hero_title: "",
    hero_subtitle: "",
    hero_cta: "",
    featured_eyebrow: "",
    featured_cta: "",
    featured_programme_id: null,
    announcements: [],
    announcements_title: "Announcements",
    stats: [],
  };
}

export function SystemContent() {
  const [cms, setCms] = useState<Cms>(emptyCms());
  const [programmes, setProgrammes] = useState<Programme[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    (async () => {
      const [{ data: c }, { data: p }] = await Promise.all([
        supabase.from("cms_landing").select("*").eq("id", 1).maybeSingle(),
        supabase.from("programmes").select("id, title").order("created_at", { ascending: true }),
      ]);
      if (c) {
        setCms({
          hero_title: c.hero_title ?? "",
          hero_subtitle: c.hero_subtitle ?? "",
          hero_cta: c.hero_cta ?? "",
          featured_eyebrow: c.featured_eyebrow ?? "",
          featured_cta: c.featured_cta ?? "",
          featured_programme_id: c.featured_programme_id ?? null,
          announcements: Array.isArray(c.announcements) ? (c.announcements as unknown as Announcement[]) : [],
          announcements_title: c.announcements_title ?? "Announcements",
          stats: Array.isArray(c.stats) ? (c.stats as unknown as Stat[]) : [],
        });
      }
      setProgrammes((p ?? []) as Programme[]);
      setLoading(false);
    })();
  }, []);

  const update = <K extends keyof Cms>(k: K, v: Cms[K]) => setCms((s) => ({ ...s, [k]: v }));

  if (loading) return <div className="py-10 text-center text-muted-fg">Loading CMS…</div>;

  return (
    <div className="flex flex-col gap-5 pb-8">
      {/* Header */}
      <header className="flex items-end justify-between gap-4">
        <div>
          <h2 className="font-heading text-2xl font-bold text-fg">Content</h2>
          <p className="mt-1 text-sm text-muted-fg">Edit the public Playground landing page.</p>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to="/playground"
            className="rounded-full border border-border px-3.5 py-2 text-[12px] font-semibold text-fg hover:bg-mut"
          >
            Preview →
          </Link>
          <button
            onClick={() => toast.success("Saved (demo)")}
            className="rounded-full bg-gold px-3.5 py-2 text-[12px] font-semibold text-bg hover:bg-gold/90"
          >
            Save changes
          </button>
        </div>
      </header>

      {/* Hero */}
      <Card title="Hero" subtitle="The top banner visitors see first.">
        <Field label="Main headline">
          <Input value={cms.hero_title} onChange={(e) => update("hero_title", e.target.value)} />
        </Field>
        <Field label="Subtitle">
          <Textarea
            value={cms.hero_subtitle}
            onChange={(e) => update("hero_subtitle", e.target.value)}
            rows={2}
          />
        </Field>
        <Field label="Primary button text">
          <Input value={cms.hero_cta} onChange={(e) => update("hero_cta", e.target.value)} />
        </Field>
      </Card>

      {/* Featured */}
      <Card title="Featured programme" subtitle="One programme spotlighted at the top of the page.">
        <Field label="Eyebrow tag">
          <Input value={cms.featured_eyebrow} onChange={(e) => update("featured_eyebrow", e.target.value)} />
        </Field>
        <Field label="Which programme">
          <Select
            value={cms.featured_programme_id ?? ""}
            onValueChange={(v) => update("featured_programme_id", v || null)}
          >
            <SelectTrigger><SelectValue placeholder="Pick a programme" /></SelectTrigger>
            <SelectContent>
              {programmes.map((p) => (
                <SelectItem key={p.id} value={p.id}>{p.title}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>
        <Field label="Button text">
          <Input value={cms.featured_cta} onChange={(e) => update("featured_cta", e.target.value)} />
        </Field>
      </Card>

      {/* Announcements */}
      <Card
        title="Announcement banners"
        subtitle="Small ribbons shown above the programme grid."
        action={
          <button
            onClick={() =>
              update("announcements", [
                ...cms.announcements,
                { id: crypto.randomUUID(), title: "New banner", body: "", color: "#C9A962" },
              ])
            }
            className="inline-flex items-center gap-1 rounded-full border border-gold/40 px-3 py-1.5 text-[12px] font-semibold text-gold hover:bg-gold/10"
          >
            <Plus size={13} /> Add banner
          </button>
        }
      >
        {cms.announcements.length === 0 && (
          <p className="text-[12px] text-muted-fg">No announcement banners yet.</p>
        )}
        {cms.announcements.map((a, i) => (
          <div key={i} className="grid grid-cols-[40px_1fr_1fr_180px_36px] items-center gap-2 rounded-[10px] border border-border bg-mut/30 p-2">
            <input
              type="color"
              value={a.color ?? "#C9A962"}
              onChange={(e) => {
                const next = [...cms.announcements];
                next[i] = { ...a, color: e.target.value };
                update("announcements", next);
              }}
              className="h-8 w-8 cursor-pointer rounded border border-border bg-transparent"
              aria-label="Colour"
            />
            <Input
              value={a.title}
              onChange={(e) => {
                const next = [...cms.announcements];
                next[i] = { ...a, title: e.target.value };
                update("announcements", next);
              }}
              placeholder="Title"
            />
            <Input
              value={a.body}
              onChange={(e) => {
                const next = [...cms.announcements];
                next[i] = { ...a, body: e.target.value };
                update("announcements", next);
              }}
              placeholder="Subtitle"
            />
            <Select
              value={a.programme_id ?? ""}
              onValueChange={(v) => {
                const next = [...cms.announcements];
                next[i] = { ...a, programme_id: v };
                update("announcements", next);
              }}
            >
              <SelectTrigger className="text-[12px]"><SelectValue placeholder="Link programme" /></SelectTrigger>
              <SelectContent>
                {programmes.map((p) => (
                  <SelectItem key={p.id} value={p.id}>{p.title}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <button
              onClick={() => update("announcements", cms.announcements.filter((_, j) => j !== i))}
              className="grid h-8 w-8 place-items-center rounded-md text-muted-fg hover:bg-danger/10 hover:text-danger"
              aria-label="Remove"
            >
              <X size={14} />
            </button>
          </div>
        ))}
      </Card>

      {/* Stats */}
      <Card
        title="Headline stats"
        subtitle="Numbers shown beneath the hero banner."
        action={
          <button
            onClick={() => update("stats", [...cms.stats, { v: "0", l: "New stat" }])}
            className="inline-flex items-center gap-1 rounded-full border border-gold/40 px-3 py-1.5 text-[12px] font-semibold text-gold hover:bg-gold/10"
          >
            <Plus size={13} /> Add stat
          </button>
        }
      >
        {cms.stats.length === 0 && <p className="text-[12px] text-muted-fg">No stats yet.</p>}
        {cms.stats.map((s, i) => (
          <div key={i} className="grid grid-cols-[140px_1fr_36px] items-center gap-2 rounded-[10px] border border-border bg-mut/30 p-2">
            <Input
              value={s.v}
              onChange={(e) => {
                const next = [...cms.stats];
                next[i] = { ...s, v: e.target.value };
                update("stats", next);
              }}
              placeholder="Value"
            />
            <Input
              value={s.l}
              onChange={(e) => {
                const next = [...cms.stats];
                next[i] = { ...s, l: e.target.value };
                update("stats", next);
              }}
              placeholder="Label"
            />
            <button
              onClick={() => update("stats", cms.stats.filter((_, j) => j !== i))}
              className="grid h-8 w-8 place-items-center rounded-md text-muted-fg hover:bg-danger/10 hover:text-danger"
              aria-label="Remove"
            >
              <X size={14} />
            </button>
          </div>
        ))}
      </Card>
    </div>
  );
}

function Card({
  title,
  subtitle,
  action,
  children,
}: {
  title: string;
  subtitle?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-[14px] border border-border bg-card p-5">
      <div className="mb-4 flex items-start justify-between gap-4">
        <div>
          <h3 className="font-heading text-[15px] font-bold text-fg">{title}</h3>
          {subtitle && <p className="mt-0.5 text-[12px] text-muted-fg">{subtitle}</p>}
        </div>
        {action}
      </div>
      <div className="flex flex-col gap-3">{children}</div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-fg">{label}</span>
      {children}
    </label>
  );
}

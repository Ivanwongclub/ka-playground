import { createFileRoute, useNavigate, Link } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Star, BookOpen, FlaskConical, Palette, Calculator, Globe } from "lucide-react";
import { toast } from "sonner";
import { ArrowLeftIcon, CheckIcon } from "@/components/icons";
import {
  WhyIcon,
  whyIconKind,
  FormatIcon,
  ClassSizeIcon,
  CertificationIcon,
  CalendarIcon,
} from "@/components/icons/WhyIcon";
import { supabase } from "@/integrations/supabase/client";
import { heroImg, galleryImg } from "@/lib/images";
import { usePageTitle } from "@/lib/page-title";
import { friendlySignIn, friendlyProgress } from "@/lib/friendly";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { EnrolmentDialog } from "@/components/shared/EnrolmentDialog";
import type { Programme, ProgrammeContent, CurriculumItem, StatItem, WhyItem, Testimonial } from "@/types/programme";


const TAB_IDS = ["overview", "curriculum", "gallery", "requirements", "integration"] as const;
type TabId = (typeof TAB_IDS)[number];

export const Route = createFileRoute("/_authenticated/playground/$id")({
  head: () => ({ meta: [{ title: "Programme — KA Playground" }] }),
  validateSearch: (s: Record<string, unknown>): { tab: TabId } => {
    const raw = typeof s.tab === "string" ? s.tab : "overview";
    return { tab: (TAB_IDS as readonly string[]).includes(raw) ? (raw as TabId) : "overview" };
  },
  component: ProgrammeDetailPage,
});


const CAT_ICON: Record<string, React.ComponentType<{ className?: string }>> = {
  Language: Globe,
  STEM: FlaskConical,
  "STEM on Car": FlaskConical,
  Arts: Palette,
  Maths: Calculator,
};

function fmtPeriod(start: string | null, end: string | null) {
  if (!start || !end) return "TBD";
  const f = (d: string) =>
    new Date(d).toLocaleDateString("en-GB", { month: "short", year: "numeric" });
  return `${f(start)} — ${f(end)}`;
}

function fmtMonth(d: string | null) {
  if (!d) return "TBD";
  return new Date(d).toLocaleDateString("en-GB", { month: "short", year: "numeric" });
}


function ProgrammeDetailPage() {
  const { id } = Route.useParams();
  const { tab } = Route.useSearch();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [enrolOpen, setEnrolOpen] = useState(false);

  const { data, isLoading: loading } = useQuery({
    queryKey: ["programme", id],
    queryFn: async () => {
      const [{ data: prog }, { data: cont }] = await Promise.all([
        supabase.from("programmes").select("*").eq("id", id).maybeSingle(),
        supabase
          .from("programme_content")
          .select("gallery_labels, stats, why_join, testimonials, curriculum, format, class_size, certification")
          .eq("programme_id", id)
          .maybeSingle(),
      ]);
      return {
        p: (prog ?? null) as Programme | null,
        c: (cont ?? null) as ProgrammeContent | null,
      };
    },
    staleTime: 60_000,
  });

  const p = data?.p ?? null;
  const c = data?.c ?? null;

  const refreshProgramme = async () => {
    await queryClient.invalidateQueries({ queryKey: ["programme", id] });
  };

  usePageTitle(p?.title ?? null);

  const tabs = useMemo<{ id: TabId; label: string }[]>(() => {
    const base: { id: TabId; label: string }[] = [
      { id: "overview", label: "Overview" },
      { id: "curriculum", label: "Curriculum" },
      { id: "gallery", label: "Gallery" },
    ];
    if (p?.featured) {
      base.push({ id: "requirements", label: "Requirements" });
      base.push({ id: "integration", label: "Integration" });
    }
    return base;
  }, [p?.featured]);

  if (loading) {
    return <div className="p-10 text-sm text-muted-foreground">Loading programme…</div>;
  }
  if (!p) {
    return (
      <div className="p-10">
        <p className="text-sm text-muted-foreground">Programme not found.</p>
        <Link to="/playground" className="text-gold underline text-sm mt-2 inline-block">
          ← Back to Playground
        </Link>
      </div>
    );
  }

  const CatIcon = CAT_ICON[p.category] ?? BookOpen;
  const spotsLeft = Math.max(p.capacity - p.enrolled_count, 0);
  const labels = c?.gallery_labels ?? ["", "", ""];
  const stats = c?.stats ?? [];
  const heroUrl = heroImg(p.id);
  const enrolPct = p.capacity > 0 ? Math.min(100, Math.round((p.enrolled_count / p.capacity) * 100)) : 0;
  const period = fmtPeriod(p.period_start, p.period_end);

  const chips = [
    p.category,
    `Ages ${p.age_range}`,
    `${p.duration_weeks} weeks`,
    `${p.enrolled_count} / ${p.capacity} enrolled · ${spotsLeft} spots left`,
    p.provider_short,
  ];

  const brand = p.brand_color;

  return (
    <>
    <div className="w-full">
      {/* Hero band — edge to edge */}
      <section
        className="relative w-full overflow-hidden"
        style={{
          minHeight: 320,
          padding: "32px 40px 24px",
          backgroundImage: `linear-gradient(135deg, rgba(0,0,0,0.55), rgba(0,0,0,0.35) 60%, rgba(0,0,0,0.55)), url(${heroUrl})`,
          backgroundSize: "cover",
          backgroundPosition: "center",
        }}
      >
        {p.featured && (
          <div className="absolute top-6 right-10 z-10">
            <span
              className="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-black"
              style={{ background: "linear-gradient(135deg, var(--gold), var(--gold-2))" }}
            >
              <Star className="h-3.5 w-3.5 fill-current" />
              Featured
            </span>
          </div>
        )}

        <div className="relative z-[1] max-w-[60%]">
          <button
            onClick={() => navigate({ to: "/playground" })}
            className="inline-flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-white/85 hover:text-white hover:bg-white/10 transition-colors mb-5"
          >
            <ArrowLeftIcon size={16} />
            Back to Playground
          </button>

          <div className="flex items-start gap-4 mb-3">
            <div
              className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl shadow-lg"
              style={{ backgroundColor: brand }}
            >
              <CatIcon className="h-7 w-7 text-white" />
            </div>
            <div className="min-w-0">
              <h1
                className="text-white tracking-tight"
                style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 36, lineHeight: 1.15 }}
              >
                {p.title}
              </h1>
            </div>
          </div>

          {p.tagline && (
            <p
              className="text-white/80 mb-5 max-w-[640px]"
              style={{ fontFamily: "var(--font-body)", fontWeight: 400, fontSize: 16, lineHeight: 1.5 }}
            >
              {p.tagline}
            </p>
          )}

          <div className="flex flex-wrap gap-2">
            {chips.map((chip) => (
              <span
                key={chip}
                className="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-medium text-white backdrop-blur-md"
              >
                {chip}
              </span>
            ))}
          </div>
        </div>
      </section>

      {/* Gallery strip */}
      <section style={{ padding: "24px 40px" }}>
        <div className="grid gap-3" style={{ gridTemplateColumns: "2fr 1fr 1fr" }}>
          {[1, 2, 3].map((n) => {
            const url = galleryImg(p.id, n as 1 | 2 | 3);
            const label = labels[n - 1] ?? "";
            return (
              <div
                key={n}
                className="group relative overflow-hidden rounded-[14px] bg-card"
                style={{ aspectRatio: "16 / 10" }}
              >
                <img
                  src={url}
                  alt={label || `Gallery ${n}`}
                  className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                  loading="lazy"
                />
                {label && (
                  <div className="absolute inset-x-0 bottom-0 p-3 pt-10 bg-gradient-to-t from-black/65 to-transparent">
                    <span
                      className="text-white text-sm font-medium"
                      style={{ textShadow: "0 1px 3px rgba(0,0,0,0.6)" }}
                    >
                      {label}
                    </span>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </section>

      {/* Urgency CTA */}
      <section style={{ margin: "0 40px" }}>
        <div
          className="flex items-center gap-6 rounded-[12px] border border-gold bg-card"
          style={{ padding: "16px 20px" }}
        >
          <div className="flex items-center gap-3 min-w-0">
            <span
              className="inline-block h-2.5 w-2.5 rounded-full bg-orange-500 animate-pulse-dot-orange shrink-0"
              aria-hidden
            />
            <div className="min-w-0">
              <div className="text-sm font-bold text-fg leading-tight">
                {spotsLeft} spots available
              </div>
              <div className="text-xs text-muted-fg truncate">
                {p.enrolled_count} students already enrolled · {period}
              </div>
            </div>
          </div>

          <div className="flex-1 min-w-[120px]">
            <div className="h-1.5 w-full rounded-full bg-mut overflow-hidden">
              <div
                className="h-full bg-gold transition-all"
                style={{ width: `${enrolPct}%` }}
              />
            </div>
          </div>

          <Button
            className="bg-gold text-black hover:bg-gold/90 font-semibold shrink-0"
            size="default"
            onClick={() => setEnrolOpen(true)}
          >
            Enrol a Student
          </Button>
        </div>
      </section>

      {/* Stats row */}
      <section style={{ margin: "0 40px", padding: "12px 0 24px" }}>
        <div className="grid grid-cols-4 gap-3">
          {stats.slice(0, 4).map((s, i) => (
            <div
              key={i}
              className="rounded-[12px]"
              style={{
                padding: 20,
                background: `linear-gradient(135deg, ${brand}26, ${brand}14)`,
                border: `1px solid ${brand}4D`,
              }}
            >
              <div
                style={{
                  fontFamily: "var(--font-heading)",
                  fontWeight: 800,
                  fontSize: 28,
                  color: brand,
                  lineHeight: 1.1,
                }}
              >
                {s.v}
              </div>
              <div
                className="text-muted-fg mt-1"
                style={{
                  fontFamily: "var(--font-body)",
                  fontWeight: 500,
                  fontSize: 12,
                  textTransform: "uppercase",
                  letterSpacing: "0.04em",
                }}
              >
                {s.l}
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Tab bar */}
      <section style={{ margin: "0 40px" }} className="border-b border-border">
        <nav className="flex">
          {tabs.map((t) => {
            const active = tab === t.id;
            return (
              <button
                key={t.id}
                onClick={() =>
                  navigate({
                    to: "/playground/$id",
                    params: { id: p.id },
                    search: { tab: t.id },
                  })
                }
                className={cn(
                  "relative -mb-px border-b-2 transition-colors",
                  active
                    ? "text-gold border-gold font-semibold"
                    : "text-muted-fg border-transparent hover:text-fg",
                )}
                style={{ padding: "12px 24px", fontSize: 14 }}
              >
                {t.label}
              </button>
            );
          })}
        </nav>
      </section>

      {/* Tab content */}
      <div style={{ padding: "32px 40px" }}>
        {tab === "overview" && (
          <OverviewTab
            programme={p}
            content={c}
            CatIcon={CatIcon}
            period={period}
            spotsLeft={spotsLeft}
            onEnrol={() => setEnrolOpen(true)}
          />
        )}
        {tab === "curriculum" && <CurriculumTab items={c?.curriculum ?? []} />}
        {tab === "gallery" && (
          <GalleryTab programmeId={p.id} labels={labels} />
        )}
        {tab === "requirements" && <RequirementsTab />}
        {tab === "integration" && <IntegrationTab programme={p} />}
      </div>
    </div>
    <EnrolmentDialog
      mode="from-programme"
      open={enrolOpen}
      onOpenChange={setEnrolOpen}
      programmeId={p.id}
      onEnrolled={refreshProgramme}
    />
    </>
  );
}

/* ---------------- Tab components ---------------- */

function OverviewTab({
  programme,
  content,
  period,
  spotsLeft,
  onEnrol,
}: {
  programme: Programme;
  content: ProgrammeContent | null;
  CatIcon: React.ComponentType<{ className?: string }>;
  period: string;
  spotsLeft: number;
  onEnrol: () => void;
}) {
  const brand = programme.brand_color;
  const why = content?.why_join ?? [];
  const testimonials = content?.testimonials ?? [];

  const highlights: { Icon: React.ComponentType<{ size?: number }>; label: string; value: string }[] = [
    { Icon: FormatIcon, label: "Format", value: content?.format ?? "Live online sessions" },
    { Icon: ClassSizeIcon, label: "Class size", value: content?.class_size ?? `Max ${programme.capacity} students` },
    { Icon: CertificationIcon, label: "Certification", value: content?.certification ?? programme.provider_short },
    { Icon: CalendarIcon, label: "Start", value: fmtMonth(programme.period_start) },
  ];

  const [expanded, setExpanded] = useState(false);
  const description = programme.description;
  const isLong = description.length > 120;
  const shortDescription = isLong ? description.slice(0, 110).trimEnd() + "…" : description;

  return (
    <div className="grid gap-8" style={{ gridTemplateColumns: "2fr 1fr" }}>
      {/* Left column */}
      <div className="space-y-10 min-w-0">
        {/* Highlights strip */}
        <section>
          <div className="grid grid-cols-4 gap-3">
            {highlights.map(({ Icon, label, value }) => (
              <div
                key={label}
                className="rounded-[12px] border border-border bg-card transition-transform duration-200 hover:-translate-y-0.5 hover:shadow-md"
                style={{ padding: 16 }}
              >
                <div style={{ color: brand }} className="mb-2">
                  <Icon size={24} />
                </div>
                <div
                  className="text-muted-fg"
                  style={{
                    fontSize: 11,
                    textTransform: "uppercase",
                    letterSpacing: "0.06em",
                    fontWeight: 500,
                  }}
                >
                  {label}
                </div>
                <div className="text-fg mt-1 leading-snug" style={{ fontSize: 15, fontWeight: 600 }}>
                  {value}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section>
          <p className="text-[14px] leading-[1.65] text-muted-fg">
            {expanded ? description : shortDescription}
            {isLong && (
              <button
                type="button"
                onClick={() => setExpanded((v) => !v)}
                className="ml-2 text-[13px] font-medium text-gold hover:underline"
              >
                {expanded ? "Show less" : "Read more"}
              </button>
            )}
          </p>
        </section>

        {why.length > 0 && (
          <section>
            <h3 className="font-heading text-[20px] font-bold text-fg mb-4">
              Why join
            </h3>
            <div className="grid grid-cols-2 gap-3">
              {why.map((w, i) => {
                const kind = whyIconKind(w);
                return (
                  <div
                    key={i}
                    className="rounded-[12px] border border-border bg-card"
                    style={{ padding: 18 }}
                  >
                    <div
                      className="mb-3 inline-flex h-9 w-9 items-center justify-center rounded-lg text-white"
                      style={{ backgroundColor: brand }}
                    >
                      <WhyIcon kind={kind} size={20} />
                    </div>
                    <div className="text-[15px] font-semibold text-fg leading-snug mb-1">
                      {w.t}
                    </div>
                    <div className="text-[13px] text-muted-fg leading-relaxed line-clamp-2">
                      {w.d}
                    </div>
                  </div>
                );
              })}
            </div>
          </section>
        )}





        {testimonials.length > 0 && (
          <section>
            <h3 className="font-heading text-[20px] font-bold text-fg mb-4">
              What families say
            </h3>
            <div className="space-y-4">
              {testimonials.map((t, i) => (
                <div
                  key={i}
                  className="rounded-[12px] border border-border bg-card relative"
                  style={{ padding: 20, borderLeft: "3px solid var(--gold)" }}
                >
                  <div
                    aria-hidden
                    className="font-heading text-gold/70 leading-none mb-2"
                    style={{ fontSize: 28 }}
                  >
                    “
                  </div>
                  <p className="italic text-[14px] text-fg leading-relaxed mb-4">
                    {t.t}
                  </p>
                  <div className="flex items-center gap-3">
                    <div
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-black"
                      style={{ background: "linear-gradient(135deg, var(--gold), var(--gold-2))" }}
                    >
                      {t.n
                        .split(/[\s,]+/)
                        .slice(0, 2)
                        .map((s) => s[0])
                        .join("")
                        .toUpperCase()}
                    </div>
                    <div className="leading-tight">
                      <div className="text-[13px] font-semibold text-fg">{t.n}</div>
                      <div className="text-[11px] text-muted-fg">{t.r}</div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}
      </div>

      {/* Right column — sticky */}
      <aside className="self-start sticky top-6 space-y-4">
        <div className="rounded-[12px] border border-border bg-card p-5">
          <h3 className="font-heading text-[16px] font-bold text-fg mb-3">
            Programme details
          </h3>
          <dl className="divide-y divide-border">
            <DetailRow label="Organiser" value={programme.organiser} />
            <DetailRow label="Duration" value={`${programme.duration_weeks} weeks`} />
            <DetailRow label="Period" value={period} />
            <DetailRow label="Age range" value={`Ages ${programme.age_range}`} />
            <DetailRow
              label="Enrolment"
              value={`${programme.enrolled_count} / ${programme.capacity} · ${spotsLeft} left`}
            />
            <DetailRow label="Status" value={programme.status} />
            <DetailRow label="Sign-in" value={friendlySignIn(programme.sign_in_method ?? "standard")} />
            <DetailRow
              label="Progress"
              value={friendlyProgress(programme.progress_updates ?? "realtime")}
            />
          </dl>
        </div>

        <div className="rounded-[12px] border border-gold/40 bg-card p-5">
          <h3 className="font-heading text-[15px] font-bold text-fg mb-1">
            Ready to enrol?
          </h3>
          <p className="text-[12px] text-muted-fg mb-4">
            Reserve a seat for your student in this cohort.
          </p>
          <Button
            className="w-full bg-gold text-black hover:bg-gold/90 font-semibold"
            onClick={onEnrol}
          >
            Enrol a Student
          </Button>
        </div>
      </aside>
    </div>
  );
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5">
      <dt className="text-[12px] font-medium uppercase tracking-wide text-muted-fg shrink-0">
        {label}
      </dt>
      <dd className="text-[13px] text-fg text-right">{value}</dd>
    </div>
  );
}

/* ---------------- Curriculum ---------------- */


function CurriculumTab({ items }: { items: { wk: string; n: string; d: string }[] }) {
  if (items.length === 0) {
    return <p className="text-sm text-muted-fg">Curriculum coming soon.</p>;
  }
  return (
    <div className="space-y-2 max-w-[820px]">
      {items.map((it, i) => (
        <div
          key={i}
          className="flex items-start gap-4 rounded-[12px] border border-border bg-card"
          style={{ padding: 18 }}
        >
          <div
            className="shrink-0 rounded-lg text-[11px] font-bold uppercase tracking-wide text-gold"
            style={{
              background: "color-mix(in oklab, var(--gold) 14%, transparent)",
              padding: "8px 10px",
              minWidth: 72,
              textAlign: "center",
            }}
          >
            {it.wk}
          </div>
          <div className="min-w-0">
            <div className="text-[15px] font-semibold text-fg leading-snug">{it.n}</div>
            <div className="text-[13px] text-muted-fg leading-relaxed mt-1">{it.d}</div>
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---------------- Gallery ---------------- */

function GalleryTab({
  programmeId,
  labels,
}: {
  programmeId: string;
  labels: string[];
}) {
  const tiles = [1, 2, 3, 1, 2, 3] as const;
  return (
    <div className="grid gap-3" style={{ gridTemplateColumns: "repeat(3, 1fr)" }}>
      {tiles.map((n, i) => {
        const label = labels[n - 1] ?? "";
        return (
          <button
            key={i}
            type="button"
            onClick={() => toast("Lightbox coming soon")}
            className="group relative block overflow-hidden rounded-[12px] bg-card text-left"
            style={{ aspectRatio: "4 / 3" }}
          >
            <img
              src={galleryImg(programmeId, n)}
              alt={label || `Gallery ${i + 1}`}
              className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
              loading="lazy"
            />
            {label && (
              <div className="absolute inset-x-0 bottom-0 p-3 pt-10 bg-gradient-to-t from-black/65 to-transparent">
                <span
                  className="text-white text-sm font-medium"
                  style={{ textShadow: "0 1px 3px rgba(0,0,0,0.6)" }}
                >
                  {label}
                </span>
              </div>
            )}
          </button>
        );
      })}
    </div>
  );
}

/* ---------------- Requirements (sc5) ---------------- */

const REQS_PREREQ = [
  "Age 9-19",
  "Basic computer literacy",
  "Interest in design or engineering",
  "Parental consent",
];
const REQS_EQUIP = [
  "CAD software access (Fusion 360)",
  "Block of Balsa for car prototype",
  "Race track session (20m)",
  "Team racing kit",
];

function RequirementsTab() {
  return (
    <div className="grid gap-8" style={{ gridTemplateColumns: "1fr 1fr" }}>
      <ReqList title="Prerequisites" items={REQS_PREREQ} />
      <ReqList title="Equipment provided" items={REQS_EQUIP} />
    </div>
  );
}

function ReqList({ title, items }: { title: string; items: string[] }) {
  return (
    <section>
      <h3 className="font-heading text-[20px] font-bold text-fg mb-4">{title}</h3>
      <ul className="space-y-3">
        {items.map((it) => (
          <li key={it} className="flex items-start gap-3 text-[13px] text-fg">
            <span className="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gold/15 text-gold">
              <CheckIcon size={14} />
            </span>
            <span>{it}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

/* ---------------- Integration (sc5) ---------------- */

function IntegrationTab({ programme }: { programme: Pick<Programme, "sign_in_method" | "progress_updates"> }) {
  return (
    <div
      className="rounded-[12px] border border-border bg-card"
      style={{ padding: 24, maxWidth: 600 }}
    >
      <h3 className="font-heading text-[18px] font-bold text-fg mb-4">How it works</h3>
      <dl className="divide-y divide-border">
        <DetailRow label="External platform" value="STEM Racing Portal" />
        <DetailRow
          label="Sign-in method"
          value={friendlySignIn(programme.sign_in_method ?? "standard")}
        />
        <DetailRow
          label="Progress updates"
          value={friendlyProgress(programme.progress_updates ?? "realtime")}
        />
        <DetailRow label="Progress tracking" value="Per-week module completion" />
        <div className="flex items-start justify-between gap-4 py-2.5">
          <dt className="text-[12px] font-medium uppercase tracking-wide text-muted-fg shrink-0">
            Connection status
          </dt>
          <dd className="text-[13px] text-fg text-right flex items-center gap-2">
            <span
              className="inline-block h-2 w-2 rounded-full bg-emerald-500"
              style={{ boxShadow: "0 0 8px rgba(16,185,129,0.7)" }}
            />
            Connected · Last sync 12 min ago
          </dd>
        </div>
      </dl>
    </div>
  );
}

import { createFileRoute, useNavigate } from "@tanstack/react-router";
import {
  Star,
  ArrowRight,
  Share2,
  ChevronRight,
  Calendar,
  Clock,
  Users,
  BookOpen,
  FlaskConical,
  Palette,
  Calculator,
  Pencil,
  Eye,
} from "lucide-react";
import { useMemo, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { heroTile, announcementImg, featuredImg, cardImg } from "@/lib/images";
import { supabase } from "@/integrations/supabase/client";
import { CategoryBadge, type Category } from "@/components/shared/CategoryBadge";
import { ProgrammeCard } from "@/components/shared/ProgrammeCard";
import { useAuth } from "@/lib/auth";
import type { Programme, ProgrammeStatus } from "@/types/programme";


export const Route = createFileRoute("/_authenticated/playground/")({
  head: () => ({ meta: [{ title: "Playground — KA Playground" }] }),
  component: PlaygroundPage,
});

type Stat = { l: string; v: string } | { label: string; value: string };
type Announcement = {
  id: string;
  img: string;
  sub: string;
  tag: string;
  scid: string;
  title: string;
  tagColor: string;
};
type Cms = {
  hero_title: string | null;
  hero_subtitle: string | null;
  stats: Stat[] | null;
  announcements_title: string | null;
  announcements: Announcement[] | null;
  featured_programme_id: string | null;
  featured_eyebrow: string | null;
  featured_cta: string | null;
};

function PlaygroundPage() {
  const [activeFilter, setActiveFilter] = useState<CatKey>("All");
  const gridRef = useRef<HTMLDivElement | null>(null);
  const { user } = useAuth();

  const { data } = useQuery({
    queryKey: ["playground", "landing"],
    queryFn: async () => {
      const [{ data: cmsData }, { data: progs }] = await Promise.all([
        supabase
          .from("cms_landing")
          .select(
            "hero_title,hero_subtitle,stats,announcements_title,announcements,featured_programme_id,featured_eyebrow,featured_cta",
          )
          .limit(1)
          .single(),
        supabase.from("programmes").select("*"),
      ]);
      return {
        cms: (cmsData ?? null) as Cms | null,
        programmes: ((progs ?? []) as Programme[]),
      };
    },
    staleTime: 60_000,
  });

  const cms = data?.cms ?? null;
  const programmes = data?.programmes ?? [];
  const featured = useMemo<Programme | null>(() => {
    const fid = cms?.featured_programme_id;
    if (!fid) return null;
    return (programmes.find((p) => p.id === fid) as Programme | undefined) ?? null;
  }, [cms?.featured_programme_id, programmes]);
  const featuredTagline = featured?.tagline ?? null;

  const handleCategoryClick = (cat: CatKey) => {
    setActiveFilter(cat);
    requestAnimationFrame(() => {
      gridRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  };

  return (
    <div className="space-y-6">
      <PlaygroundHero cms={cms} />
      <BannerRibbon cms={cms} />
      <FeaturedShowcase cms={cms} prog={featured} tagline={featuredTagline} />
      <CategoriesSection programmes={programmes} onPick={handleCategoryClick} />
      <AllProgrammesGrid
        ref={gridRef}
        programmes={programmes}
        activeFilter={activeFilter}
        setActiveFilter={setActiveFilter}
      />
      {user?.role === "admin" && <AdminEditBar />}
    </div>
  );
}


/* -------------------------------------------------------------------------- */
/* HERO                                                                       */
/* -------------------------------------------------------------------------- */

const HERO_TILES: { id: string; category: Category; title: string }[] = [
  { id: "sc5", category: "STEM on Car", title: "STEM on Car" },
  { id: "sc1", category: "Language", title: "Cambridge English Online" },
  { id: "sc3", category: "Arts", title: "Creative Arts Studio" },
];

function statValue(s: Stat) {
  return "v" in s ? s.v : s.value;
}
function statLabel(s: Stat) {
  return "l" in s ? s.l : s.label;
}

function PlaygroundHero({ cms }: { cms: Cms | null }) {
  const title = cms?.hero_title ?? "Where every child finds their spark";
  const subtitle =
    cms?.hero_subtitle ??
    "Discover world-class programmes in STEM, Languages, Arts, and Maths — built for Hong Kong learners aged 6 to 19.";
  const stats = cms?.stats ?? [];

  const sparkIdx = title.toLowerCase().lastIndexOf("spark");
  const head = sparkIdx >= 0 ? title.slice(0, sparkIdx) : title;
  const tail = sparkIdx >= 0 ? title.slice(sparkIdx) : "";

  return (
    <section className="relative isolate">
      <div
        aria-hidden
        className="pointer-events-none absolute -top-32 right-0 -z-10 h-[200%] w-[60%]"
        style={{
          background:
            "radial-gradient(circle, rgba(201,169,98,0.18), transparent 60%)",
        }}
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-32 left-0 -z-10 h-[160%] w-[40%]"
        style={{
          background:
            "radial-gradient(circle, rgba(124,58,237,0.15), transparent 60%)",
        }}
      />

      <div className="grid grid-cols-1 gap-10 lg:grid-cols-[1.05fr_1fr] lg:items-center">
        <div className="flex flex-col gap-6">
          <div className="inline-flex w-fit items-center gap-2 rounded-full bg-gold-soft px-3 py-1 text-xs font-semibold uppercase tracking-wider text-gold">
            <Star className="h-3.5 w-3.5 fill-current" /> Kings Armour Education
          </div>
          <h1 className="font-heading text-5xl font-bold leading-[1.05] text-fg lg:text-6xl">
            {head}
            {tail && (
              <span className="text-gold-gradient bg-clip-text text-transparent">
                {tail}
              </span>
            )}
          </h1>
          <p className="max-w-xl text-base leading-relaxed text-muted-fg lg:text-lg">
            {subtitle}
          </p>
          <div className="flex flex-wrap items-center gap-3 pt-2">
            <button className="inline-flex items-center gap-2 rounded-full bg-gold-gradient px-6 py-3 text-sm font-semibold text-black shadow-lg shadow-black/20 transition hover:opacity-90">
              Browse programmes <ArrowRight className="h-4 w-4" />
            </button>
            <button
              onClick={() => toast("Share modal coming soon")}
              className="inline-flex items-center gap-2 rounded-full border border-border bg-transparent px-6 py-3 text-sm font-semibold text-fg transition hover:bg-card/50"
            >
              <Share2 className="h-4 w-4" /> Share this page
            </button>
          </div>

          {stats.length > 0 && (
            <div
              className="mt-2 grid max-w-[680px] grid-cols-4 gap-5 border-t pt-5"
              style={{ borderColor: "rgba(255,255,255,0.08)" }}
            >
              {stats.slice(0, 4).map((s, i) => (
                <div key={i} className="flex flex-col gap-1">
                  <span
                    className="font-heading text-gold-gradient bg-clip-text text-transparent"
                    style={{ fontWeight: 600, fontSize: "28px", lineHeight: 1 }}
                  >
                    {statValue(s)}
                  </span>
                  <span className="text-[12px] font-normal text-muted-fg">
                    {statLabel(s)}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="grid h-[460px] grid-cols-2 grid-rows-2 gap-4 lg:h-[520px]">
          <CollageTile
            tile={HERO_TILES[0]}
            className="col-start-1 row-span-2 row-start-1"
          />
          <CollageTile tile={HERO_TILES[1]} className="col-start-2 row-start-1" />
          <CollageTile tile={HERO_TILES[2]} className="col-start-2 row-start-2" />
        </div>
      </div>
    </section>
  );
}

function CollageTile({
  tile,
  className = "",
}: {
  tile: { id: string; category: Category; title: string };
  className?: string;
}) {
  return (
    <div
      className={`group relative overflow-hidden rounded-2xl border border-border bg-muted shadow-xl shadow-black/20 ${className}`}
    >
      <img
        src={heroTile(tile.id)}
        alt={tile.title}
        className="h-full w-full object-cover transition duration-700 group-hover:scale-105"
        loading="lazy"
      />
      <div
        className="pointer-events-none absolute inset-0"
        style={{
          background:
            "linear-gradient(to top, rgba(0,0,0,0.55), transparent 60%)",
        }}
      />
      {/* Category badge — bumped to 16px inset + dark glass bg for legibility */}
      <div className="absolute" style={{ top: 16, left: 16 }}>
        <CategoryBadge
          category={tile.category}
          className="bg-black/40 backdrop-blur-md ring-1 ring-white/10"
        />
      </div>
      <div
        className="absolute bottom-4 left-4 right-4 text-white"
        style={{ textShadow: "0 2px 8px rgba(0,0,0,0.6)" }}
      >
        <div className="font-heading text-base font-semibold leading-tight lg:text-lg">
          {tile.title}
        </div>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* BANNER RIBBON                                                              */
/* -------------------------------------------------------------------------- */

function BannerRibbon({ cms }: { cms: Cms | null }) {
  const { user } = useAuth();
  const isAdmin = user?.role === "admin";
  const items = cms?.announcements ?? [];
  if (items.length === 0) return null;

  return (
    <section style={{ padding: "28px 0 8px" }}>
      <div className="mb-3 flex items-center justify-between">
        <h3 className="font-heading text-sm font-semibold uppercase tracking-wider text-muted-fg">
          {cms?.announcements_title ?? "What's happening at KA"}
        </h3>
        {isAdmin && (
          <a
            href="/settings/content"
            className="text-xs font-semibold text-gold hover:underline"
          >
            Manage →
          </a>
        )}
      </div>
      <div
        className="grid gap-[10px]"
        style={{ gridTemplateColumns: "repeat(auto-fit, minmax(280px, 1fr))" }}
      >
        {items.map((a) => (
          <BannerCard key={a.id} a={a} />
        ))}
      </div>
    </section>
  );
}

function BannerCard({ a }: { a: Announcement }) {
  const bgImg = announcementImg(a.img);
  return (
    <a
      href={`/playground/${a.scid}`}
      className="group relative flex items-center gap-4 overflow-hidden rounded-xl px-4 transition duration-300 hover:brightness-110 hover:scale-[1.01]"
      style={{
        height: 78,
        borderLeft: `3px solid ${a.tagColor}`,
        backgroundImage: `linear-gradient(135deg, ${a.tagColor}33, transparent), url(${bgImg})`,
        backgroundSize: "cover",
        backgroundPosition: "center",
      }}
    >
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0"
        style={{ background: "rgba(15,15,25,0.78)" }}
      />
      <div className="relative flex flex-1 items-center gap-3">
        <span
          className="rounded-md text-[10px] font-bold uppercase tracking-wider text-white"
          style={{
            background: a.tagColor,
            padding: "4px 8px",
          }}
        >
          {a.tag}
        </span>
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-bold text-white">{a.title}</div>
          <div className="truncate text-xs text-white/60">{a.sub}</div>
        </div>
      </div>
      <ChevronRight className="relative h-4 w-4 text-white/50 transition group-hover:translate-x-0.5 group-hover:text-white" />
    </a>
  );
}

/* -------------------------------------------------------------------------- */
/* FEATURED SHOWCASE                                                          */
/* -------------------------------------------------------------------------- */

function fmtPeriod(start: string | null, end: string | null) {
  if (!start || !end) return null;
  const fmt = (d: string) =>
    new Date(d).toLocaleDateString("en-GB", { month: "short", year: "numeric" });
  return `${fmt(start)} — ${fmt(end)}`;
}

function FeaturedShowcase({
  cms,
  prog,
  tagline,
}: {
  cms: Cms | null;
  prog: Programme | null;
  tagline: string | null;
}) {
  if (!prog || !cms) return null;
  const period = fmtPeriod(prog.period_start, prog.period_end);
  const spotsLeft = Math.max(0, prog.capacity - prog.enrolled_count);
  const eyebrow = `${prog.category.toUpperCase()} · ${prog.age_range} YEARS`;
  const ctaLabel = (cms.featured_cta ?? `Learn more about ${prog.title}`) + " →";
  const subTagline = tagline ?? prog.description.split(/[.!?]/)[0] + ".";

  return (
    <section style={{ margin: "24px 0 0" }}>
      <div
        className="grid min-h-[340px] grid-cols-1 overflow-hidden border border-border bg-card md:grid-cols-2"
        style={{ borderRadius: 18 }}
      >
        {/* Left — image */}
        <div
          className="relative min-h-[260px]"
          style={{
            backgroundImage: `url(${featuredImg(prog.id)})`,
            backgroundSize: "cover",
            backgroundPosition: "center",
          }}
        >
          <div
            aria-hidden
            className="absolute inset-0"
            style={{
              background:
                "linear-gradient(135deg, rgba(0,0,0,0.15), rgba(0,0,0,0.4))",
            }}
          />
          <div
            className="absolute inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold text-white"
            style={{
              top: 20,
              left: 20,
              background: "rgba(255,255,255,0.14)",
              backdropFilter: "blur(10px)",
              border: "1px solid rgba(255,255,255,0.18)",
            }}
          >
            <Star className="h-3.5 w-3.5 fill-current" />
            {cms.featured_eyebrow ?? "Featured this season"}
          </div>
        </div>

        {/* Right — content */}
        <div
          className="flex flex-col gap-4"
          style={{ padding: "32px 36px" }}
        >
          <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-gold">
            {eyebrow}
          </div>
          <h2
            className="font-heading text-fg"
            style={{ fontSize: 28, fontWeight: 800, lineHeight: 1.15 }}
          >
            {prog.title}
          </h2>
          <p className="max-w-prose text-sm leading-relaxed text-muted-fg">
            {subTagline}
          </p>

          <div className="flex flex-wrap gap-2 pt-1">
            {period && <MetaChip icon={<Calendar className="h-3.5 w-3.5" />} label={period} />}
            <MetaChip icon={<Clock className="h-3.5 w-3.5" />} label={`${prog.duration_weeks} weeks`} />
            <MetaChip
              icon={<Users className="h-3.5 w-3.5" />}
              label={`${prog.enrolled_count} / ${prog.capacity} enrolled · ${spotsLeft} spots left`}
            />
          </div>

          <div className="pt-2">
            <a
              href={`/playground/${prog.id}`}
              className="inline-flex items-center gap-2 rounded-full bg-gold-gradient px-5 py-2.5 text-sm font-semibold text-black shadow-lg shadow-black/20 transition hover:opacity-90"
            >
              {ctaLabel}
            </a>
          </div>
        </div>
      </div>
    </section>
  );
}

function MetaChip({ icon, label }: { icon: React.ReactNode; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/40 px-3 py-1 text-xs text-fg">
      {icon}
      {label}
    </span>
  );
}

/* -------------------------------------------------------------------------- */
/* CATEGORIES + GRID + ADMIN BAR                                              */
/* -------------------------------------------------------------------------- */

type CatKey = "All" | "Language" | "STEM" | "Arts" | "Maths";

const CAT_META: Record<
  Exclude<CatKey, "All">,
  { color: string; soft: string; icon: typeof BookOpen }
> = {
  Language: { color: "#6366F1", soft: "rgba(99,102,241,0.15)", icon: BookOpen },
  STEM:     { color: "#7C3AED", soft: "rgba(124,58,237,0.15)", icon: FlaskConical },
  Arts:     { color: "#EC4899", soft: "rgba(236,72,153,0.15)", icon: Palette },
  Maths:    { color: "#F97316", soft: "rgba(249,115,22,0.15)", icon: Calculator },
};

const CATS: Exclude<CatKey, "All">[] = ["Language", "STEM", "Arts", "Maths"];

function CategoriesSection({
  programmes,
  onPick,
}: {
  programmes: Programme[];
  onPick: (c: CatKey) => void;
}) {
  return (
    <section style={{ padding: "40px 0 12px" }}>
      <h2 className="font-heading text-2xl font-bold text-fg">Explore by area</h2>
      <p className="mt-1 text-sm text-muted-fg">Pick what excites your child</p>
      <div
        className="mt-5 grid gap-3"
        style={{ gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))" }}
      >
        {CATS.map((cat) => {
          const meta = CAT_META[cat];
          const count = programmes.filter((p) => p.category === cat).length;
          const Icon = meta.icon;
          return (
            <button
              key={cat}
              type="button"
              onClick={() => onPick(cat)}
              className="group relative overflow-hidden rounded-[14px] border border-border bg-card p-[18px] text-left transition-all hover:scale-[1.02] hover:border-gold/40"
            >
              <span
                aria-hidden
                className="pointer-events-none absolute -right-6 -top-6 h-[160px] w-[160px] rounded-full"
                style={{ background: meta.color, opacity: 0.15 }}
              />
              <div
                className="relative flex h-11 w-11 items-center justify-center rounded-xl"
                style={{ background: meta.soft, color: meta.color }}
              >
                <Icon className="h-5 w-5" strokeWidth={1.75} />
              </div>
              <div className="relative mt-4">
                <div className="text-[15px] font-semibold text-fg">{cat}</div>
                <div className="mt-0.5 text-xs text-muted-fg">
                  {count} {count === 1 ? "programme" : "programmes"}
                </div>
              </div>
            </button>
          );
        })}
      </div>
    </section>
  );
}



function AllProgrammesGrid({
  ref,
  programmes,
  activeFilter,
  setActiveFilter,
}: {
  ref: React.RefObject<HTMLDivElement | null>;
  programmes: Programme[];
  activeFilter: CatKey;
  setActiveFilter: (c: CatKey) => void;
}) {
  const navigate = useNavigate();
  const filtered = useMemo(() => {
    const list =
      activeFilter === "All"
        ? programmes
        : programmes.filter((p) => p.category === activeFilter);
    return [...list].sort((a, b) => {
      const fa = (a as Programme & { featured?: boolean }).featured ? 1 : 0;
      const fb = (b as Programme & { featured?: boolean }).featured ? 1 : 0;
      if (fa !== fb) return fb - fa;
      return b.enrolled_count - a.enrolled_count;
    });
  }, [programmes, activeFilter]);

  const pills: CatKey[] = ["All", "Language", "STEM", "Arts", "Maths"];

  return (
    <section ref={ref} style={{ padding: "40px 0" }}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="font-heading text-2xl font-bold text-fg">All programmes</h2>
          <p className="mt-1 text-sm text-muted-fg">
            Showing {filtered.length} {filtered.length === 1 ? "programme" : "programmes"}
          </p>
        </div>
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        {pills.map((p) => {
          const active = p === activeFilter;
          return (
            <button
              key={p}
              type="button"
              onClick={() => setActiveFilter(p)}
              className={
                active
                  ? "rounded-full border border-gold bg-gold-soft px-4 py-1.5 text-xs font-semibold text-gold"
                  : "rounded-full border border-border bg-transparent px-4 py-1.5 text-xs font-medium text-muted-fg transition hover:text-fg"
              }
            >
              {p}
            </button>
          );
        })}
      </div>

      <div
        className="mt-5 grid gap-4"
        style={{ gridTemplateColumns: "repeat(auto-fill, minmax(280px, 1fr))" }}
      >
        {filtered.map((p) => {
          const featured = (p as Programme & { featured?: boolean }).featured;
          const status = ((p as Programme & { status?: string }).status ?? "Open") as ProgrammeStatus;
          const period = fmtPeriod(p.period_start, p.period_end) ?? undefined;
          return (
            <ProgrammeCard
              key={p.id}
              title={p.title}
              category={p.category as Category}
              description={p.tagline ?? p.description}
              status={status}
              period={period}
              enrolled={p.enrolled_count}
              capacity={p.capacity}
              ageRange={p.age_range}
              featured={featured}
              image={cardImg(p.id)}
              brandColor={p.brand_color}
              onClick={() => navigate({ to: `/playground/${p.id}` as never })}
            />
          );
        })}
      </div>
    </section>
  );
}

function AdminEditBar() {
  const notice = () => toast("Coming in Phase 4");
  return (
    <div
      className="fixed left-1/2 z-40 flex -translate-x-1/2 items-center gap-1 rounded-full border border-gold/60 bg-card-elev/90 px-3 py-2 shadow-xl shadow-black/40 backdrop-blur-md"
      style={{ bottom: 24 }}
    >
      <button
        type="button"
        onClick={notice}
        className="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-fg transition hover:bg-white/5"
      >
        <Pencil className="h-3.5 w-3.5" /> Edit this page
      </button>
      <span aria-hidden className="h-4 w-px bg-border" />
      <button
        type="button"
        onClick={notice}
        className="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-fg transition hover:bg-white/5"
      >
        <Eye className="h-3.5 w-3.5" /> Preview public
      </button>
    </div>
  );
}


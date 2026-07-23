import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
  Eye,
  Share2,
  Copy,
  CheckCircle2,
  Sparkles,
  Calendar,
  Users,
  Award,
  ArrowRight,
} from "lucide-react";
import { supabase } from "@/integrations/supabase/client";
import { heroImg, galleryImg } from "@/lib/images";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { CategoryBadge, type Category } from "@/components/shared/CategoryBadge";

export const Route = createFileRoute("/p/$id")({
  head: () => ({
    meta: [
      { title: "Programme — Armour Academy" },
      {
        name: "description",
        content: "Preview a Kings Armour Education programme — open for enrolment.",
      },
      { property: "og:title", content: "Programme — Armour Academy" },
      {
        property: "og:description",
        content: "Preview a Kings Armour Education programme — open for enrolment.",
      },
    ],
  }),
  component: PublicProgramme,
});

type ProgrammeRow = {
  id: string;
  title: string;
  tagline: string | null;
  category: string;
  age_range: string;
  duration_weeks: number;
  capacity: number;
  enrolled_count: number;
  status: string;
  brand_color: string;
  period_start: string | null;
  period_end: string | null;
  provider_short: string | null;
  featured: boolean;
};

function fmtPeriod(start: string | null, end: string | null) {
  if (!start || !end) return "Period TBD";
  const f = (d: string) =>
    new Date(d).toLocaleDateString("en-GB", { month: "short", year: "numeric" });
  return `${f(start)} — ${f(end)}`;
}

function PublicProgramme() {
  const { id } = Route.useParams();
  const [p, setP] = useState<ProgrammeRow | null>(null);
  const [loading, setLoading] = useState(true);
  const [shareOpen, setShareOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const { data } = await supabase
        .from("programmes")
        .select(
          "id, title, tagline, category, age_range, duration_weeks, capacity, enrolled_count, status, brand_color, period_start, period_end, provider_short, featured",
        )
        .eq("id", id)
        .maybeSingle();
      if (cancelled) return;
      setP(data as ProgrammeRow | null);
      setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  const shareUrl =
    typeof window !== "undefined" ? window.location.href : `/p/${id}`;

  if (loading) {
    return (
      <div className="min-h-screen bg-bg flex items-center justify-center text-sm text-muted-fg">
        Loading…
      </div>
    );
  }

  if (!p) {
    return (
      <div className="min-h-screen bg-bg flex items-center justify-center px-6">
        <div className="text-center">
          <h1
            className="text-fg"
            style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 28 }}
          >
            Programme not found
          </h1>
          <p className="mt-2 text-sm text-muted-fg">
            The programme you're looking for may have been moved or unpublished.
          </p>
          <Link
            to="/"
            className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-gold hover:text-gold/80"
          >
            Back home <ArrowRight className="h-4 w-4" />
          </Link>
        </div>
      </div>
    );
  }

  const heroUrl = heroImg(p.id);
  const spotsLeft = Math.max(p.capacity - p.enrolled_count, 0);
  const enrolPct =
    p.capacity > 0
      ? Math.min(100, Math.round((p.enrolled_count / p.capacity) * 100))
      : 0;

  return (
    <div className="min-h-screen bg-bg">
      {/* Public preview banner */}
      <div
        className="sticky top-0 z-40 flex items-center justify-center gap-3 px-4 py-2.5 text-xs font-semibold text-white"
        style={{
          background:
            "linear-gradient(90deg, oklch(0.68 0.18 45), oklch(0.72 0.20 35))",
        }}
      >
        <Eye className="h-3.5 w-3.5" />
        <span>
          Public preview — anyone with this link can see this page
        </span>
        <button
          type="button"
          onClick={() => setShareOpen(true)}
          className="inline-flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-0.5 text-[11px] hover:bg-white/30 transition"
        >
          <Share2 className="h-3 w-3" /> Share
        </button>
      </div>

      {/* Topbar minimal */}
      <header className="flex items-center justify-between px-10 py-5 border-b border-border bg-bg">
        <Link to="/" className="flex items-center gap-2.5">
          <div
            className="grid h-8 w-8 place-items-center rounded-[8px]"
            style={{
              background: "linear-gradient(135deg, var(--gold), var(--gold-2))",
            }}
          >
            <Sparkles className="h-4 w-4 text-black" />
          </div>
          <div
            className="text-fg"
            style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 16 }}
          >
            Armour Academy
          </div>
        </Link>
        <Link
          to="/login"
          className="rounded-full bg-gold px-4 py-1.5 text-xs font-semibold text-black hover:bg-gold/90 transition"
        >
          Sign in
        </Link>
      </header>

      {/* Hero */}
      <section
        className="relative w-full overflow-hidden"
        style={{
          minHeight: 360,
          padding: "48px 40px 40px",
          backgroundImage: `linear-gradient(135deg, rgba(0,0,0,0.55), rgba(0,0,0,0.35) 60%, rgba(0,0,0,0.55)), url(${heroUrl})`,
          backgroundSize: "cover",
          backgroundPosition: "center",
        }}
      >
        <div className="relative z-[1] max-w-[720px]">
          <CategoryBadge category={p.category as Category} />
          <h1
            className="mt-4 text-white tracking-tight"
            style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 42, lineHeight: 1.1 }}
          >
            {p.title}
          </h1>
          {p.tagline && (
            <p
              className="mt-4 text-white/85 max-w-[600px]"
              style={{ fontFamily: "var(--font-body)", fontSize: 17, lineHeight: 1.5 }}
            >
              {p.tagline}
            </p>
          )}
          <div className="mt-6 flex flex-wrap gap-2">
            {[
              `Ages ${p.age_range}`,
              `${p.duration_weeks} weeks`,
              fmtPeriod(p.period_start, p.period_end),
              p.provider_short ?? "Kings Armour",
            ].map((chip) => (
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

      {/* Stats strip */}
      <section className="px-10 py-8">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <StatTile
            icon={<Users className="h-4 w-4" />}
            value={`${p.enrolled_count}/${p.capacity}`}
            label="Enrolled"
            color={p.brand_color}
          />
          <StatTile
            icon={<Calendar className="h-4 w-4" />}
            value={`${p.duration_weeks} wks`}
            label="Duration"
            color={p.brand_color}
          />
          <StatTile
            icon={<Award className="h-4 w-4" />}
            value="Certificate"
            label="On completion"
            color={p.brand_color}
          />
          <StatTile
            icon={<CheckCircle2 className="h-4 w-4" />}
            value={spotsLeft > 0 ? `${spotsLeft} spots` : "Full"}
            label={spotsLeft > 0 ? "Available" : "Waitlist open"}
            color={p.brand_color}
          />
        </div>
      </section>

      {/* Gallery */}
      <section className="px-10 pb-8">
        <h2
          className="text-fg mb-3"
          style={{ fontFamily: "var(--font-heading)", fontWeight: 700, fontSize: 18 }}
        >
          Inside the programme
        </h2>
        <div className="grid gap-3" style={{ gridTemplateColumns: "2fr 1fr 1fr" }}>
          {[1, 2, 3].map((n) => (
            <div
              key={n}
              className="overflow-hidden rounded-[14px] bg-card"
              style={{ aspectRatio: "16 / 10" }}
            >
              <img
                src={galleryImg(p.id, n as 1 | 2 | 3)}
                alt=""
                className="h-full w-full object-cover"
                loading="lazy"
              />
            </div>
          ))}
        </div>
      </section>

      {/* CTA */}
      <section className="px-10 pb-14">
        <div
          className="rounded-[16px] border border-gold/40 bg-card p-7 flex flex-col md:flex-row items-start md:items-center justify-between gap-5"
          style={{
            background:
              "linear-gradient(135deg, var(--card), color-mix(in oklab, var(--gold) 8%, var(--card)))",
          }}
        >
          <div className="flex-1">
            <h3
              className="text-fg"
              style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 22 }}
            >
              Ready to join?
            </h3>
            <p className="mt-1.5 text-sm text-muted-fg max-w-[480px]">
              {spotsLeft > 0
                ? `Only ${spotsLeft} spots left — secure your child's place today. Sign in to your account or contact our team.`
                : "This programme is full — sign in to join the waitlist for the next cohort."}
            </p>
            <div className="mt-3 flex items-center gap-2">
              <div className="h-1.5 w-48 rounded-full bg-mut overflow-hidden">
                <div
                  className="h-full transition-all"
                  style={{ width: `${enrolPct}%`, background: p.brand_color }}
                />
              </div>
              <span className="text-[11px] font-semibold text-muted-fg tabular-nums">
                {enrolPct}% full
              </span>
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <Button
              variant="ghost"
              onClick={() => setShareOpen(true)}
              className="text-sm"
            >
              <Share2 className="h-4 w-4 mr-1.5" /> Share
            </Button>
            <Link
              to="/login"
              className="inline-flex items-center gap-1.5 rounded-full bg-gold px-5 py-2.5 text-sm font-bold text-black hover:bg-gold/90 transition"
            >
              Enrol now <ArrowRight className="h-4 w-4" />
            </Link>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-border px-10 py-6 flex items-center justify-between text-xs text-muted-fg">
        <span>© Kings Armour Education</span>
        <div className="flex items-center gap-4">
          <a href="#" className="hover:text-fg">Privacy</a>
          <a href="#" className="hover:text-fg">Terms</a>
          <a href="#" className="hover:text-fg">Contact</a>
        </div>
      </footer>

      {/* Share dialog */}
      <Dialog open={shareOpen} onOpenChange={setShareOpen}>
        <DialogContent className="sm:max-w-[480px]">
          <DialogHeader>
            <DialogTitle>Share this programme</DialogTitle>
            <DialogDescription>
              Send this link to parents, students, or partners.
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2 mt-2">
            <Input value={shareUrl} readOnly className="font-mono text-xs" />
            <Button
              onClick={() => {
                if (typeof navigator !== "undefined" && navigator.clipboard) {
                  navigator.clipboard.writeText(shareUrl);
                }
                toast.success("Link copied to clipboard");
              }}
              className="bg-gold text-black hover:bg-gold/90 font-semibold shrink-0"
            >
              <Copy className="h-3.5 w-3.5 mr-1.5" /> Copy
            </Button>
          </div>
          <div className="mt-4 grid grid-cols-4 gap-2">
            {["WhatsApp", "Email", "X", "LinkedIn"].map((c) => (
              <button
                key={c}
                type="button"
                onClick={() => toast.info(`Share via ${c} — coming soon`)}
                className="rounded-[8px] border border-border px-3 py-2 text-xs font-semibold text-muted-fg transition hover:bg-mut hover:text-fg"
              >
                {c}
              </button>
            ))}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function StatTile({
  icon,
  value,
  label,
  color,
}: {
  icon: React.ReactNode;
  value: string;
  label: string;
  color: string;
}) {
  return (
    <div className="rounded-[12px] border border-border bg-card p-4">
      <div className="flex items-center gap-2">
        <span
          className="grid h-7 w-7 place-items-center rounded-[6px] text-white"
          style={{ background: color }}
        >
          {icon}
        </span>
        <span className="text-[11px] uppercase tracking-wider text-muted-fg font-semibold">
          {label}
        </span>
      </div>
      <div
        className="mt-2 text-fg"
        style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 22 }}
      >
        {value}
      </div>
    </div>
  );
}

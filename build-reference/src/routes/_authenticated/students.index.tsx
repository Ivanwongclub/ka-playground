import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { supabase } from "@/integrations/supabase/client";
import { StatRing } from "@/components/shared/StatRing";
import { EmptyState } from "@/components/shared/EmptyState";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { getLastStudentTab } from "@/lib/studentTabMemory";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/_authenticated/students/")({
  head: () => ({ meta: [{ title: "Students — KA Playground" }] }),
  component: StudentsPage,
});

type EnrolmentRow = {
  id: string;
  programme_id: string;
  status: string;
  progress_percent: number | null;
  enrolled_at: string | null;
  programmes: {
    category: string;
    brand_color: string;
    title: string;
  } | null;
};

type StudentRow = {
  id: string;
  full_name: string;
  full_name_zh: string | null;
  region: string | null;
  photo_url: string | null;
  enrolments: EnrolmentRow[] | null;
};

type CatFilter = "All" | "Language" | "STEM" | "Arts" | "Maths";
const CATS: CatFilter[] = ["All", "Language", "STEM", "Arts", "Maths"];

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? "")
    .join("");
}

function relativeTime(iso: string | null): string {
  if (!iso) return "—";
  const then = new Date(iso).getTime();
  const diff = Date.now() - then;
  const sec = Math.floor(diff / 1000);
  if (sec < 60) return "just now";
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min} min${min === 1 ? "" : "s"} ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr} hour${hr === 1 ? "" : "s"} ago`;
  const day = Math.floor(hr / 24);
  if (day < 30) return `${day} day${day === 1 ? "" : "s"} ago`;
  const mo = Math.floor(day / 30);
  if (mo < 12) return `${mo} month${mo === 1 ? "" : "s"} ago`;
  const yr = Math.floor(mo / 12);
  return `${yr} year${yr === 1 ? "" : "s"} ago`;
}

function ChevronRightKA({ size = 18 }: { size?: number }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M9 5.6 L15.4 12 L9 18.4" />
    </svg>
  );
}

function StudentsPage() {
  const navigate = useNavigate();
  const [filter, setFilter] = useState<CatFilter>("All");

  const { data: students = [], isPending: loading } = useQuery({
    queryKey: ["students", "list"],
    queryFn: async () => {
      const { data, error } = await supabase
        .from("students")
        .select(
          `
          id, full_name, full_name_zh, region, photo_url,
          enrolments (
            id, programme_id, status, progress_percent, enrolled_at,
            programmes ( category, brand_color, title )
          )
        `,
        )
        .order("updated_at", { ascending: false });
      if (error) {
        console.error("students load", error);
        throw error;
      }
      return (data ?? []) as unknown as StudentRow[];
    },
  });

  const enriched = useMemo(() => {
    return students.map((s) => {
      const enrols = s.enrolments ?? [];
      const active = enrols.filter((e) => e.status === "active");
      const completed = enrols.filter((e) => e.status === "completed");
      const avgProgress =
        active.length === 0
          ? 0
          : Math.round(
              active.reduce((sum, e) => sum + (e.progress_percent ?? 0), 0) /
                active.length,
            );
      const schemeDots = active
        .map((e) => ({
          color: e.programmes?.brand_color ?? "#c9a962",
          title: e.programmes?.title ?? "Programme",
        }));
      const categories = new Set(
        enrols
          .map((e) => e.programmes?.category)
          .filter((c): c is string => !!c),
      );
      const lastUpdated = enrols.reduce<string | null>((acc, e) => {
        if (!e.enrolled_at) return acc;
        if (!acc) return e.enrolled_at;
        return new Date(e.enrolled_at) > new Date(acc) ? e.enrolled_at : acc;
      }, null);
      return {
        ...s,
        active,
        completed,
        avgProgress,
        schemeDots,
        categories,
        lastUpdated,
      };
    });
  }, [students]);

  const totalCount = enriched.length;
  const activeCount = enriched.filter((s) => s.active.length > 0).length;
  const completedCount = enriched.filter((s) => s.completed.length > 0).length;

  const filtered =
    filter === "All"
      ? enriched
      : enriched.filter((s) => s.categories.has(filter));

  return (
    <div className="space-y-5">
      <div>
        <h1 className="font-heading text-display font-bold text-fg">Students</h1>
        <p className="mt-1 text-sm text-muted-fg">
          Everyone you have access to, with their active schemes and progress.
        </p>
      </div>

      {/* Top bar */}
      <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center md:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          <span
            className="inline-flex items-center rounded-full bg-gold-soft text-gold"
            style={{ padding: "8px 14px", fontSize: 12, fontWeight: 600 }}
          >
            {totalCount} {totalCount === 1 ? "student" : "students"}
          </span>
          <span
            className="inline-flex items-center rounded-full bg-success/15 text-success"
            style={{ padding: "8px 14px", fontSize: 12, fontWeight: 600 }}
          >
            {activeCount} active
          </span>
          <span
            className="inline-flex items-center rounded-full bg-purple/15 text-purple"
            style={{ padding: "8px 14px", fontSize: 12, fontWeight: 600 }}
          >
            {completedCount} completed
          </span>
        </div>

        <div
          className="-mx-[var(--spacing-page-x)] flex items-center gap-2 overflow-x-auto px-[var(--spacing-page-x)] pb-1 md:mx-0 md:overflow-visible md:px-0 md:pb-0"
          role="group"
          aria-label="Filter by category"
        >
          {CATS.map((c) => {
            const active = filter === c;
            const count =
              c === "All"
                ? enriched.length
                : enriched.filter((s) => s.categories.has(c)).length;
            return (
              <button
                key={c}
                type="button"
                onClick={() => setFilter(c)}
                aria-pressed={active}
                className={cn(
                  "inline-flex shrink-0 items-center gap-1.5 rounded-full transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/40",
                  "min-h-[40px] md:min-h-0",
                  active
                    ? "bg-gold text-bg border border-gold shadow-sm"
                    : "bg-transparent text-muted-fg border border-border hover:text-fg hover:bg-mut",
                )}
                style={{ padding: "6px 14px", fontSize: 12, fontWeight: 600 }}
              >
                <span>{c}</span>
                <span
                  className={cn(
                    "inline-flex items-center justify-center rounded-full text-[10px] font-bold leading-none",
                    active ? "bg-bg/20 text-bg" : "bg-mut text-muted-fg",
                  )}
                  style={{ minWidth: 18, padding: "2px 5px" }}
                >
                  {count}
                </span>
              </button>
            );
          })}
        </div>
      </div>

      {/* Table */}
      {loading ? (
        <StudentsTableSkeleton />
      ) : filtered.length === 0 ? (
        <EmptyState
          title="No students"
          description="No students match this filter."
        />
      ) : (
        <div className="overflow-x-auto rounded-[14px] border border-border bg-card">
         <div className="min-w-[820px]">
          {/* Header */}
          <div
            className="grid items-center bg-muted text-[11px] font-medium uppercase tracking-wider text-muted-fg"
            style={{
              gridTemplateColumns:
                "minmax(220px,2fr) 100px minmax(140px,1fr) 80px 120px 32px",
              padding: "12px 20px",
              gap: 16,
            }}
          >
            <div>Student</div>
            <div>Region</div>
            <div>Schemes</div>
            <div>Progress</div>
            <div>Updated</div>
            <div />
          </div>


          <TooltipProvider delayDuration={150}>
            {filtered.map((s) => (
              <button
                key={s.id}
                type="button"
                onClick={() => navigate({ to: "/students/$id", params: { id: s.id }, search: { tab: getLastStudentTab() } })}
                className="grid w-full items-center border-t border-border text-left transition hover:bg-muted/40"
                style={{
                  gridTemplateColumns:
                    "minmax(220px,2fr) 100px minmax(140px,1fr) 80px 120px 32px",
                  padding: "14px 20px",
                  gap: 16,
                }}
              >
                {/* Student */}
                <div className="flex items-center gap-3 min-w-0">
                  <span
                    className="inline-flex shrink-0 items-center justify-center rounded-full font-heading text-[13px] font-bold"
                    style={{
                      width: 36,
                      height: 36,
                      background:
                        "linear-gradient(135deg, #e7c98a, #c9a962 60%, #a07f3e)",
                      color: "#2a1f3d",
                    }}
                    aria-hidden
                  >
                    {initials(s.full_name)}
                  </span>
                  <div className="min-w-0">
                    <div className="truncate text-[14px] font-semibold text-fg">
                      {s.full_name}
                    </div>
                    {s.full_name_zh && (
                      <div className="truncate text-[12px] text-muted-fg">
                        {s.full_name_zh}
                      </div>
                    )}
                  </div>
                </div>

                {/* Region */}
                <div>
                  <span
                    className="inline-flex items-center rounded-full bg-muted text-[11px] text-muted-fg"
                    style={{ padding: "3px 10px" }}
                  >
                    {s.region ?? "—"}
                  </span>
                </div>

                {/* Schemes */}
                <div className="flex items-center pointer-events-none md:pointer-events-auto" style={{ gap: 4 }}>
                  {s.schemeDots.length === 0 ? (
                    <span className="text-[12px] text-muted-fg">—</span>
                  ) : (
                    s.schemeDots.map((d, i) => (
                      <Tooltip key={i}>
                        <TooltipTrigger asChild>
                          <span
                            className="inline-block rounded-full"
                            style={{
                              width: 8,
                              height: 8,
                              background: d.color,
                            }}
                          />
                        </TooltipTrigger>
                        <TooltipContent side="top">{d.title}</TooltipContent>
                      </Tooltip>
                    ))
                  )}
                </div>

                {/* Progress */}
                <div>
                  <StatRing percent={s.avgProgress} size={36} stroke={4} />
                </div>

                {/* Updated */}
                <div className="text-[12px] text-muted-fg">
                  {relativeTime(s.lastUpdated)}
                </div>

                {/* Chevron */}
                <div className="flex justify-end text-muted-fg">
                  <ChevronRightKA />
                </div>
              </button>
            ))}
          </TooltipProvider>
         </div>
        </div>
      )}
    </div>
  );
}

function StudentsTableSkeleton() {
  return (
    <div className="overflow-hidden rounded-[14px] border border-border bg-card">
      <div
        className="grid items-center bg-muted text-[11px] font-medium uppercase tracking-wider text-muted-fg"
        style={{
          gridTemplateColumns: "minmax(220px,2fr) 100px minmax(140px,1fr) 80px 120px 32px",
          padding: "12px 20px",
          gap: 16,
        }}
      >
        <div>Student</div>
        <div>Region</div>
        <div>Schemes</div>
        <div>Progress</div>
        <div>Updated</div>
        <div />
      </div>
      {Array.from({ length: 5 }).map((_, i) => (
        <div
          key={i}
          className="grid items-center border-t border-border"
          style={{
            gridTemplateColumns: "minmax(220px,2fr) 100px minmax(140px,1fr) 80px 120px 32px",
            padding: "14px 20px",
            gap: 16,
          }}
        >
          <div className="flex items-center gap-3">
            <div className="h-9 w-9 shrink-0 animate-pulse rounded-full bg-mut" />
            <div className="flex-1 space-y-1.5">
              <div className="h-3 w-32 animate-pulse rounded bg-mut" />
              <div className="h-2.5 w-20 animate-pulse rounded bg-mut/60" />
            </div>
          </div>
          <div className="h-3 w-12 animate-pulse rounded bg-mut" />
          <div className="h-3 w-24 animate-pulse rounded bg-mut" />
          <div className="h-9 w-9 animate-pulse rounded-full bg-mut" />
          <div className="h-3 w-16 animate-pulse rounded bg-mut" />
          <div />
        </div>
      ))}
    </div>
  );
}

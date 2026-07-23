import { createFileRoute, useNavigate, Link } from "@tanstack/react-router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { format } from "date-fns";
import { toast } from "sonner";
import { CalendarIcon } from "lucide-react";
import { ArrowLeftIcon } from "@/components/icons";
import { supabase } from "@/integrations/supabase/client";
import { useAuth } from "@/lib/auth";
import { StatRing } from "@/components/shared/StatRing";
import { EmptyState } from "@/components/shared/EmptyState";
import { CategoryBadge, type Category } from "@/components/shared/CategoryBadge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Calendar } from "@/components/ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { cn } from "@/lib/utils";
import { EnrolmentDialog, AddEnrolmentButton } from "@/components/shared/EnrolmentDialog";

import { TAB_IDS, type TabId, setLastStudentTab } from "@/lib/studentTabMemory";

export const Route = createFileRoute("/_authenticated/students/$id")({
  head: () => ({ meta: [{ title: "Student — KA Playground" }] }),
  validateSearch: (s: Record<string, unknown>): { tab: TabId } => {
    const raw = typeof s.tab === "string" ? s.tab : "profile";
    return {
      tab: (TAB_IDS as readonly string[]).includes(raw)
        ? (raw as TabId)
        : "profile",
    };
  },
  component: StudentDetailPage,
});

type ProgrammeRef = {
  id: string;
  title: string;
  category: string;
  brand_color: string;
  period_start: string | null;
  period_end: string | null;
  external_lms_url: string | null;
};

type Enrolment = {
  id: string;
  programme_id: string;
  status: string;
  progress_percent: number | null;
  completed_modules: number | null;
  total_modules: number | null;
  last_quiz_name: string | null;
  last_quiz_score: number | null;
  grade: string | null;
  enrolled_at: string | null;
  programmes: ProgrammeRef | null;
};

type Relationship = {
  id: string;
  role: string;
  related_user_id: string;
  users: { id: string; full_name: string; email: string } | null;
};

type Note = {
  id: string;
  content: string;
  created_at: string;
  author_id: string | null;
  users: { full_name: string } | null;
};

type Task = {
  id: string;
  title: string;
  status: string | null;
  due_date: string | null;
  assigned_to: string | null;
  users: { full_name: string } | null;
};

type Student = {
  id: string;
  full_name: string;
  full_name_zh: string | null;
  dob: string | null;
  gender: string | null;
  region: string | null;
  photo_url: string | null;
  bio: string | null;
  enrolments: Enrolment[] | null;
  student_relationships: Relationship[] | null;
  notes: Note[] | null;
  tasks: Task[] | null;
};

const SELECT = `
  *,
  enrolments (
    *,
    programmes ( id, title, category, brand_color, period_start, period_end, external_lms_url )
  ),
  student_relationships (
    id, role, related_user_id,
    users:related_user_id ( id, full_name, email )
  ),
  notes ( id, content, created_at, author_id, users:author_id ( full_name ) ),
  tasks ( id, title, status, due_date, assigned_to, users:assigned_to ( full_name ) )
`;

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
  const diff = Date.now() - new Date(iso).getTime();
  const min = Math.floor(diff / 60000);
  if (min < 1) return "just now";
  if (min < 60) return `${min}m ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr}h ago`;
  const day = Math.floor(hr / 24);
  if (day < 30) return `${day}d ago`;
  return new Date(iso).toLocaleDateString("en-GB");
}

const ROLE_STYLES: Record<string, string> = {
  parent: "bg-gold-soft text-gold",
  teacher: "bg-purple/15 text-purple",
  school: "bg-cyan/15 text-cyan",
  admin: "bg-pink/15 text-pink",
  student: "bg-indigo/15 text-indigo",
};

const STATUS_STYLES: Record<string, string> = {
  active: "bg-success/15 text-success",
  completed: "bg-purple/15 text-purple",
  paused: "bg-orange/15 text-orange",
  cancelled: "bg-danger/15 text-danger",
};

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function StudentDetailPage() {
  const { id } = Route.useParams();
  const { tab } = Route.useSearch();
  const navigate = useNavigate();
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [enrolOpen, setEnrolOpen] = useState(false);

  const isValidId = UUID_RE.test(id);

  const { data: student = null, isPending, isError } = useQuery({
    queryKey: ["students", id],
    enabled: isValidId,
    queryFn: async () => {
      const { data, error } = await supabase
        .from("students")
        .select(SELECT)
        .eq("id", id)
        .maybeSingle();
      if (error) {
        console.error("student load", error);
        throw error;
      }
      return (data as unknown as Student) ?? null;
    },
  });

  const fetchStudent = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ["students", id] });
    queryClient.invalidateQueries({ queryKey: ["students", "list"] });
  }, [queryClient, id]);

  // Remember the active tab so opening another student restores it.
  useEffect(() => {
    setLastStudentTab(tab);
  }, [tab]);

  const setTab = (t: TabId) =>
    navigate({ to: "/students/$id", params: { id }, search: { tab: t } });

  const loading = isValidId && isPending;
  const notFound = !isValidId || isError || (!isPending && !student);

  if (loading) return <DetailSkeleton />;

  if (notFound || !student) {
    return (
      <EmptyState
        title="Student not found"
        description="This student doesn't exist or you don't have access."
        action={
          <Link
            to="/students"
            className="inline-flex items-center gap-2 rounded-full bg-gold-soft px-4 py-2 text-sm font-semibold text-gold"
          >
            <ArrowLeftIcon size={16} /> Back to students
          </Link>
        }
      />
    );
  }

  const enrolments = student.enrolments ?? [];
  const activeEnrolments = enrolments.filter((e) => e.status === "active");
  const rels = student.student_relationships ?? [];
  const notes = (student.notes ?? []).slice().sort((a, b) =>
    b.created_at.localeCompare(a.created_at),
  );
  const tasks = student.tasks ?? [];

  const TABS: { id: TabId; label: string; count?: number }[] = [
    { id: "profile", label: "Profile" },
    { id: "enrolments", label: "Enrolments", count: enrolments.length },
    { id: "notes", label: "Notes", count: notes.length },
    { id: "tasks", label: "Tasks", count: tasks.length },
  ];

  return (
    <div className="space-y-4">
      {/* Header card */}
      <header
        className="flex flex-wrap items-start gap-6 rounded-[14px] border border-border bg-card"
        style={{ padding: 24 }}
      >
        {/* Avatar */}
        <div style={{ width: 80 }} className="shrink-0">
          <span
            className="inline-flex items-center justify-center rounded-full font-heading"
            style={{
              width: 72,
              height: 72,
              fontSize: 24,
              fontWeight: 700,
              background:
                "linear-gradient(135deg, #e7c98a, #c9a962 60%, #a07f3e)",
              color: "#2a1f3d",
            }}
            aria-hidden
          >
            {initials(student.full_name)}
          </span>
        </div>

        {/* Identity */}
        <div className="min-w-0 flex-1">
          <Link
            to="/students"
            className="inline-flex items-center gap-1 text-xs text-muted-fg hover:text-fg"
          >
            <ArrowLeftIcon size={14} /> All students
          </Link>
          <h1
            className="font-heading text-fg break-words"
            style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}
          >
            {student.full_name}
          </h1>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-muted-fg">
            {student.full_name_zh && (
              <span style={{ fontFamily: "var(--font-hk, 'Noto Sans HK')" }}>
                {student.full_name_zh}
              </span>
            )}
            {student.full_name_zh && <span className="opacity-50">·</span>}
            <span>DOB {student.dob ?? "—"}</span>
            <span className="opacity-50">·</span>
            <span
              className="inline-flex items-center rounded-full bg-muted text-[11px]"
              style={{ padding: "2px 8px" }}
            >
              {student.region ?? "—"}
            </span>
          </div>

          {rels.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-1.5">
              {rels.map((r) => (
                <span
                  key={r.id}
                  className={cn(
                    "inline-flex items-center rounded-full text-[11px] font-semibold",
                    ROLE_STYLES[r.role] ?? "bg-muted text-muted-fg",
                  )}
                  style={{ padding: "3px 10px" }}
                >
                  {r.role}: {r.users?.full_name ?? "Unknown"}
                </span>
              ))}
            </div>
          )}
        </div>

        {/* Enrolment rings */}
        <div className="flex w-full flex-wrap items-start justify-start gap-3 lg:ml-auto lg:w-auto lg:justify-end">
          {activeEnrolments.slice(0, activeEnrolments.length > 3 ? 2 : 3).map((e) => (
            <div key={e.id} className="flex flex-col items-center" style={{ maxWidth: 80 }}>
              <StatRing
                percent={e.progress_percent ?? 0}
                size={52}
                stroke={5}
                color={e.programmes?.brand_color ?? "var(--gold)"}
              />
              <div className="mt-1 line-clamp-1 text-center text-[12px] text-muted-fg">
                {e.programmes?.title ?? "Programme"}
              </div>
            </div>
          ))}
          {activeEnrolments.length > 3 && (
            <div className="flex flex-col items-center" style={{ maxWidth: 80 }}>
              <div
                className="flex items-center justify-center rounded-full border border-dashed border-border text-[13px] font-semibold text-muted-fg"
                style={{ width: 52, height: 52 }}
              >
                +{activeEnrolments.length - 2}
              </div>
              <div className="mt-1 text-center text-[12px] text-muted-fg">more</div>
            </div>
          )}
        </div>
      </header>

      {/* Tab bar — sticky beneath topbar */}
      <nav className="sticky top-0 z-20 -mx-2 flex w-[calc(100%+1rem)] border-b border-border bg-bg/95 px-2 backdrop-blur supports-[backdrop-filter]:bg-bg/80">
        {TABS.map((t) => {
          const active = tab === t.id;
          return (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              aria-current={active ? "page" : undefined}
              className={cn(
                "relative -mb-px flex-1 md:flex-none inline-flex items-center justify-center whitespace-nowrap border-b-2 px-2 md:px-5 py-3 text-[13px] md:text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/40",
                active
                  ? "text-gold border-gold font-semibold"
                  : "text-muted-fg border-transparent hover:text-fg",
              )}
              style={{ minHeight: 44 }}
            >
              {t.label}
              {typeof t.count === "number" && (
                <span className="ml-1 text-[12px] opacity-70">({t.count})</span>
              )}
            </button>
          );
        })}
      </nav>

      {/* Tab content */}
      <div>
        {tab === "profile" && (
          <ProfileTab
            student={student}
            rels={rels}
            isAdmin={user?.role === "admin"}
          />
        )}
        {tab === "enrolments" && (
          <EnrolmentsTab
            enrolments={enrolments}
            onAdd={() => setEnrolOpen(true)}
          />
        )}
        {tab === "notes" && (
          <NotesTab
            studentId={student.id}
            notes={notes}
            currentUserId={user?.id ?? null}
            isAdmin={user?.role === "admin"}
            onChange={fetchStudent}
          />
        )}
        {tab === "tasks" && (
          <TasksTab
            studentId={student.id}
            tasks={tasks}
            rels={rels}
            currentUserId={user?.id ?? null}
            currentUserName={user?.full_name ?? "Me"}
            onChange={fetchStudent}
          />
        )}
      </div>
      <EnrolmentDialog
        mode="from-student"
        open={enrolOpen}
        onOpenChange={setEnrolOpen}
        studentId={student.id}
        onEnrolled={fetchStudent}
      />
    </div>
  );
}

/* ---------- Skeleton ---------- */

function DetailSkeleton() {
  return (
    <div className="space-y-4">
      <div
        className="animate-pulse rounded-[14px] border border-border bg-card"
        style={{ padding: 24, height: 160 }}
      />
      <div className="h-10 border-b border-border" />
      <div className="space-y-3">
        <div className="h-32 animate-pulse rounded-[12px] border border-border bg-card" />
        <div className="h-32 animate-pulse rounded-[12px] border border-border bg-card" />
      </div>
    </div>
  );
}

/* ---------- Profile tab ---------- */

function ProfileTab({
  student,
  rels,
  isAdmin,
}: {
  student: Student;
  rels: Relationship[];
  isAdmin: boolean;
}) {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <section
        className="rounded-[12px] border border-border bg-card"
        style={{ padding: 24 }}
      >
        <h3 className="font-heading text-[16px] font-bold text-fg">
          Personal Information
        </h3>
        <dl className="mt-4 divide-y divide-border">
          <Row label="Full Name" value={student.full_name} />
          <Row label="Chinese Name" value={student.full_name_zh ?? "—"} />
          <Row label="Date of Birth" value={student.dob ?? "—"} />
          <Row label="Gender" value={student.gender ?? "—"} />
          <Row label="Region" value={student.region ?? "—"} />
        </dl>
        {isAdmin && (
          <div className="mt-4 flex justify-end">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => toast("Edit details — coming in P4")}
            >
              Edit details
            </Button>
          </div>
        )}
      </section>

      <section
        className="rounded-[12px] border border-border bg-card"
        style={{ padding: 24 }}
      >
        <h3 className="font-heading text-[16px] font-bold text-fg">
          Linked Relationships
        </h3>
        {rels.length === 0 ? (
          <p className="mt-4 text-sm text-muted-fg">No relationships linked.</p>
        ) : (
          <ul className="mt-4 space-y-2">
            {rels.map((r) => (
              <li
                key={r.id}
                className="flex items-center justify-between rounded-md border border-border bg-muted/40 px-3 py-2"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={cn(
                      "inline-flex rounded-full text-[11px] font-semibold uppercase",
                      ROLE_STYLES[r.role] ?? "bg-muted text-muted-fg",
                    )}
                    style={{ padding: "2px 8px" }}
                  >
                    {r.role}
                  </span>
                  <span className="text-[14px] font-semibold text-fg">
                    {r.users?.full_name ?? "Unknown"}
                  </span>
                </div>
                {isAdmin && (
                  <button
                    type="button"
                    onClick={() => toast("Relationship actions — P4")}
                    className="rounded px-2 text-muted-fg hover:bg-muted hover:text-fg"
                    aria-label="More"
                  >
                    ⋯
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between py-2.5">
      <dt className="text-[12px] text-muted-fg">{label}</dt>
      <dd className="text-[14px] text-fg">{value}</dd>
    </div>
  );
}

/* ---------- Enrolments tab ---------- */

function EnrolmentsTab({
  enrolments,
  onAdd,
}: {
  enrolments: Enrolment[];
  onAdd: () => void;
}) {
  if (enrolments.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <div className="flex justify-end">
          <AddEnrolmentButton onClick={onAdd} />
        </div>
        <EmptyState
          title="No enrolments"
          description="This student hasn't been enrolled in any programmes yet."
        />
      </div>
    );
  }
  return (
    <div className="flex flex-col gap-3">
      <div className="flex justify-end">
        <AddEnrolmentButton onClick={onAdd} />
      </div>
      {enrolments.map((e) => {
        const p = e.programmes;
        const period =
          p?.period_start && p?.period_end
            ? `${format(new Date(p.period_start), "MMM yyyy")} — ${format(new Date(p.period_end), "MMM yyyy")}`
            : "Period TBD";
        const pct = e.progress_percent ?? 0;
        const status = e.status ?? "active";
        return (
          <div
            key={e.id}
            className="flex flex-col gap-4 rounded-[12px] border border-border bg-card sm:flex-row sm:items-stretch"
            style={{ padding: 18 }}
          >
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <h4 className="text-[15px] font-semibold text-fg">
                  {p?.title ?? "Programme"}
                </h4>
                {p && (
                  <CategoryBadge category={p.category as Category} />
                )}
                <span
                  className={cn(
                    "ml-auto inline-flex rounded-full text-[11px] font-semibold capitalize",
                    STATUS_STYLES[status] ?? "bg-muted text-muted-fg",
                  )}
                  style={{ padding: "2px 10px" }}
                >
                  {status}
                </span>
              </div>
              <div className="mt-1 text-[12px] text-muted-fg">{period}</div>

              <div
                className="mt-3 w-full overflow-hidden rounded-full bg-muted"
                style={{ height: 6 }}
              >
                <div
                  className="h-full rounded-full transition-all"
                  style={{
                    width: `${Math.max(0, Math.min(100, pct))}%`,
                    background: p?.brand_color ?? "var(--gold)",
                  }}
                />
              </div>
              <div className="mt-2 text-[12px] text-muted-fg">
                {(e.completed_modules ?? 0)}/{(e.total_modules ?? 0)} modules
                {e.last_quiz_name && (
                  <> · Last quiz: {e.last_quiz_name} ({e.last_quiz_score ?? 0}%)</>
                )}
                {e.grade && <> · Grade {e.grade}</>}
              </div>
            </div>

            <div className="flex flex-col items-stretch justify-start gap-2 sm:w-[180px]">
              <a
                href={p?.external_lms_url ?? "#"}
                target={p?.external_lms_url ? "_blank" : undefined}
                rel="noreferrer"
                className="inline-flex items-center justify-center rounded-full bg-gold-gradient px-4 py-2 text-[13px] font-semibold text-black transition hover:opacity-90"
              >
                Open programme
              </a>
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* ---------- Notes tab ---------- */

function NotesTab({
  studentId,
  notes,
  currentUserId,
  isAdmin,
  onChange,
}: {
  studentId: string;
  notes: Note[];
  currentUserId: string | null;
  isAdmin: boolean;
  onChange: () => void;
}) {
  const [content, setContent] = useState("");
  const [saving, setSaving] = useState(false);

  const post = async () => {
    if (!content.trim() || !currentUserId) return;
    setSaving(true);
    const { error } = await supabase.from("notes").insert({
      student_id: studentId,
      author_id: currentUserId,
      content: content.trim(),
    });
    setSaving(false);
    if (error) {
      toast.error("Failed to post note");
      return;
    }
    setContent("");
    onChange();
  };

  const del = async (id: string) => {
    const { error } = await supabase.from("notes").delete().eq("id", id);
    if (error) {
      toast.error("Failed to delete note");
      return;
    }
    onChange();
  };

  return (
    <div className="space-y-4">
      <div
        className="flex flex-col gap-3 rounded-[12px] border border-border bg-card sm:flex-row"
        style={{ padding: 16 }}
      >
        <Textarea
          placeholder="Add a note about this student..."
          value={content}
          onChange={(e) => setContent(e.target.value)}
          rows={3}
          className="flex-1"
        />
        <div className="flex sm:flex-col sm:justify-end">
          <Button
            onClick={post}
            disabled={!content.trim() || saving}
            className="bg-gold-gradient text-black hover:opacity-90"
          >
            {saving ? "Posting…" : "Post note"}
          </Button>
        </div>
      </div>

      {notes.length === 0 ? (
        <EmptyState title="No notes yet" description="Be the first to leave a note about this student." />
      ) : (
        <div className="flex flex-col gap-3">
          {notes.map((n) => {
            const canDelete = isAdmin || n.author_id === currentUserId;
            const authorName = n.users?.full_name ?? "Unknown";
            return (
              <article
                key={n.id}
                className="rounded-[12px] border border-border bg-card"
                style={{ padding: 16 }}
              >
                <header className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span
                      className="inline-flex items-center justify-center rounded-full bg-gold-soft text-[11px] font-bold text-gold"
                      style={{ width: 28, height: 28 }}
                      aria-hidden
                    >
                      {initials(authorName)}
                    </span>
                    <span className="text-[13px] font-semibold text-fg">
                      {authorName}
                    </span>
                  </div>
                  <div className="flex items-center gap-2">
                    <time className="text-[12px] text-muted-fg">
                      {relativeTime(n.created_at)}
                    </time>
                    {canDelete && (
                      <button
                        type="button"
                        onClick={() => del(n.id)}
                        className="rounded px-2 text-muted-fg hover:bg-muted hover:text-danger"
                        aria-label="Delete note"
                      >
                        ⋯
                      </button>
                    )}
                  </div>
                </header>
                <p className="mt-2 text-[14px] leading-relaxed text-fg">
                  {n.content}
                </p>
              </article>
            );
          })}
        </div>
      )}
    </div>
  );
}

/* ---------- Tasks tab ---------- */

function TasksTab({
  studentId,
  tasks,
  rels,
  currentUserId,
  currentUserName,
  onChange,
}: {
  studentId: string;
  tasks: Task[];
  rels: Relationship[];
  currentUserId: string | null;
  currentUserName: string;
  onChange: () => void;
}) {
  const [title, setTitle] = useState("");
  const [due, setDue] = useState<Date | undefined>();
  const [assignee, setAssignee] = useState<string>(currentUserId ?? "");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (currentUserId && !assignee) setAssignee(currentUserId);
  }, [currentUserId, assignee]);

  const assigneeOptions = useMemo(() => {
    const opts: { id: string; name: string }[] = [];
    if (currentUserId) opts.push({ id: currentUserId, name: currentUserName });
    for (const r of rels) {
      if (r.users && !opts.find((o) => o.id === r.users!.id)) {
        opts.push({ id: r.users.id, name: r.users.full_name });
      }
    }
    return opts;
  }, [rels, currentUserId, currentUserName]);

  const add = async () => {
    if (!title.trim()) return;
    setSaving(true);
    const { error } = await supabase.from("tasks").insert({
      student_id: studentId,
      title: title.trim(),
      assigned_to: assignee || null,
      due_date: due ? format(due, "yyyy-MM-dd") : null,
      status: "pending",
    });
    setSaving(false);
    if (error) {
      toast.error("Failed to add task");
      return;
    }
    setTitle("");
    setDue(undefined);
    onChange();
  };

  const toggle = async (t: Task) => {
    const next = t.status === "completed" ? "pending" : "completed";
    const { error } = await supabase
      .from("tasks")
      .update({ status: next })
      .eq("id", t.id);
    if (error) {
      toast.error("Failed to update task");
      return;
    }
    onChange();
  };

  const sorted = useMemo(() => {
    return tasks.slice().sort((a, b) => {
      const sa = a.status === "completed" ? 1 : 0;
      const sb = b.status === "completed" ? 1 : 0;
      if (sa !== sb) return sa - sb;
      const da = a.due_date ?? "9999";
      const db = b.due_date ?? "9999";
      return da.localeCompare(db);
    });
  }, [tasks]);

  return (
    <div className="space-y-4">
      <div
        className="grid gap-3 rounded-[12px] border border-border bg-card sm:grid-cols-[1fr_180px_180px_auto]"
        style={{ padding: 16 }}
      >
        <Input
          placeholder="Task title"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
        />
        <Popover>
          <PopoverTrigger asChild>
            <Button variant="outline" className="justify-start font-normal">
              <CalendarIcon className="mr-2 h-4 w-4" />
              {due ? format(due, "PP") : <span className="text-muted-fg">Due date</span>}
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-auto p-0" align="start">
            <Calendar
              mode="single"
              selected={due}
              onSelect={setDue}
              initialFocus
              className={cn("p-3 pointer-events-auto")}
            />
          </PopoverContent>
        </Popover>
        <Select value={assignee} onValueChange={setAssignee}>
          <SelectTrigger>
            <SelectValue placeholder="Assignee" />
          </SelectTrigger>
          <SelectContent>
            {assigneeOptions.map((o) => (
              <SelectItem key={o.id} value={o.id}>
                {o.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Button
          onClick={add}
          disabled={!title.trim() || saving}
          className="bg-gold-gradient text-black hover:opacity-90"
        >
          {saving ? "Adding…" : "Add task"}
        </Button>
      </div>

      {sorted.length === 0 ? (
        <EmptyState title="No tasks" description="Add the first task for this student above." />
      ) : (
        <div className="flex flex-col gap-2">
          {sorted.map((t) => {
            const done = t.status === "completed";
            return (
              <div
                key={t.id}
                className="flex items-center gap-3 rounded-[12px] border border-border bg-card"
                style={{ padding: "14px 18px" }}
              >
                <Checkbox
                  checked={done}
                  onCheckedChange={() => toggle(t)}
                  aria-label="Toggle task"
                />
                <div className="min-w-0 flex-1">
                  <div
                    className={cn(
                      "truncate text-[14px]",
                      done ? "text-muted-fg line-through" : "text-fg",
                    )}
                  >
                    {t.title}
                  </div>
                  {t.users?.full_name && (
                    <div className="text-[11px] text-muted-fg">
                      Assigned to {t.users.full_name}
                    </div>
                  )}
                </div>
                {t.due_date && (
                  <span
                    className="inline-flex items-center rounded-full bg-muted text-[11px] text-muted-fg"
                    style={{ padding: "3px 10px" }}
                  >
                    Due {format(new Date(t.due_date), "MMM d")}
                  </span>
                )}
                <span
                  className={cn(
                    "inline-flex rounded-full text-[11px] font-semibold capitalize",
                    done
                      ? "bg-success/15 text-success"
                      : "bg-orange/15 text-orange",
                  )}
                  style={{ padding: "3px 10px" }}
                >
                  {done ? "completed" : "pending"}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

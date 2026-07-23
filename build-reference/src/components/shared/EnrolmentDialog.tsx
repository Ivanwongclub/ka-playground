import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { Plus, Search, ChevronRight } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { supabase } from "@/integrations/supabase/client";
import { useAuth } from "@/lib/auth";
import { cn } from "@/lib/utils";
import { CategoryBadge, type Category } from "@/components/shared/CategoryBadge";

type Mode = "from-programme" | "from-student";

type StudentRow = {
  id: string;
  full_name: string;
  full_name_zh: string | null;
};

type ProgrammeRow = {
  id: string;
  title: string;
  category: string;
  age_range: string;
  brand_color: string;
  capacity: number;
  enrolled_count: number;
  status: string;
  period_start: string | null;
  period_end: string | null;
};

type Props = {
  mode: Mode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Required when mode === "from-programme" */
  programmeId?: string;
  /** Required when mode === "from-student" */
  studentId?: string;
  onEnrolled?: () => void;
};

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join("");
}

function fmtPeriod(start: string | null, end: string | null) {
  if (!start || !end) return "Period TBD";
  const f = (d: string) =>
    new Date(d).toLocaleDateString("en-GB", { month: "short", year: "numeric" });
  return `${f(start)} — ${f(end)}`;
}

export function EnrolmentDialog({
  mode,
  open,
  onOpenChange,
  programmeId,
  studentId,
  onEnrolled,
}: Props) {
  const { user } = useAuth();

  const [step, setStep] = useState<1 | 2>(1);
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  // Fixed side
  const [fixedProgramme, setFixedProgramme] = useState<ProgrammeRow | null>(null);
  const [fixedStudent, setFixedStudent] = useState<StudentRow | null>(null);

  // Variable side
  const [students, setStudents] = useState<StudentRow[]>([]);
  const [programmes, setProgrammes] = useState<ProgrammeRow[]>([]);

  // Selected
  const [chosenStudent, setChosenStudent] = useState<StudentRow | null>(null);
  const [chosenProgramme, setChosenProgramme] = useState<ProgrammeRow | null>(null);

  // Reset on close
  useEffect(() => {
    if (!open) {
      setStep(1);
      setQuery("");
      setChosenStudent(null);
      setChosenProgramme(null);
    }
  }, [open]);

  // Load data when dialog opens
  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    (async () => {
      setLoading(true);

      if (mode === "from-programme" && programmeId) {
        // Load fixed programme
        const { data: prog } = await supabase
          .from("programmes")
          .select("id, title, category, age_range, brand_color, capacity, enrolled_count, status, period_start, period_end")
          .eq("id", programmeId)
          .maybeSingle();
        if (cancelled) return;
        setFixedProgramme(prog as ProgrammeRow | null);

        // Existing enrolments to exclude
        const { data: existing } = await supabase
          .from("enrolments")
          .select("student_id")
          .eq("programme_id", programmeId);
        const excluded = new Set((existing ?? []).map((e) => e.student_id));

        // Students RLS will scope automatically (admin sees all)
        const { data: studs } = await supabase
          .from("students")
          .select("id, full_name, full_name_zh")
          .order("full_name", { ascending: true });
        if (cancelled) return;
        setStudents((studs ?? []).filter((s) => !excluded.has(s.id)) as StudentRow[]);
      }

      if (mode === "from-student" && studentId) {
        const { data: stud } = await supabase
          .from("students")
          .select("id, full_name, full_name_zh")
          .eq("id", studentId)
          .maybeSingle();
        if (cancelled) return;
        setFixedStudent(stud as StudentRow | null);

        const { data: existing } = await supabase
          .from("enrolments")
          .select("programme_id")
          .eq("student_id", studentId);
        const excluded = new Set((existing ?? []).map((e) => e.programme_id));

        const { data: progs } = await supabase
          .from("programmes")
          .select("id, title, category, age_range, brand_color, capacity, enrolled_count, status, period_start, period_end")
          .neq("status", "Closed")
          .order("title", { ascending: true });
        if (cancelled) return;
        setProgrammes((progs ?? []).filter((p) => !excluded.has(p.id)) as ProgrammeRow[]);
      }

      setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [open, mode, programmeId, studentId]);

  // Filtered lists
  const filteredStudents = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return students;
    return students.filter(
      (s) =>
        s.full_name.toLowerCase().includes(q) ||
        (s.full_name_zh ?? "").toLowerCase().includes(q),
    );
  }, [students, query]);

  const filteredProgrammes = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return programmes;
    return programmes.filter(
      (p) =>
        p.title.toLowerCase().includes(q) ||
        p.category.toLowerCase().includes(q),
    );
  }, [programmes, query]);

  // Resolve final pair for Step 2
  const stuFinal = mode === "from-programme" ? chosenStudent : fixedStudent;
  const progFinal = mode === "from-programme" ? fixedProgramme : chosenProgramme;

  // Validation
  const isFull = !!progFinal && progFinal.enrolled_count >= progFinal.capacity;
  const isClosed = !!progFinal && progFinal.status === "Closed";
  const blockMsg = isClosed
    ? "Enrolment closed for this programme"
    : isFull
      ? "This programme is full"
      : null;

  async function handleConfirm() {
    if (!stuFinal || !progFinal || !user) return;
    if (blockMsg) return;
    setSubmitting(true);
    const { error } = await supabase.from("enrolments").insert({
      student_id: stuFinal.id,
      programme_id: progFinal.id,
      enrolled_by: user.id,
      status: "active",
      progress_percent: 0,
      completed_modules: 0,
      total_modules: null,
    });
    setSubmitting(false);
    if (error) {
      toast.error(`Could not enrol — ${error.message}`);
      return;
    }
    toast.success(`Enrolled ${stuFinal.full_name} in ${progFinal.title}`);
    onOpenChange(false);
    onEnrolled?.();
  }

  const title = mode === "from-programme" ? "Enrol a student" : "Add enrolment";
  const subtitle =
    mode === "from-programme"
      ? "Pick a student to enrol into this programme."
      : "Pick a programme to enrol this student into.";

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[520px] p-0 gap-0 overflow-hidden">
        <DialogHeader className="px-6 pt-6 pb-3 border-b border-border">
          <div className="flex items-center justify-between gap-3">
            <div>
              <DialogTitle className="text-[16px] font-bold text-fg">{title}</DialogTitle>
              <DialogDescription className="text-[12px] text-muted-fg mt-0.5">
                {subtitle}
              </DialogDescription>
            </div>
            <span className="shrink-0 rounded-full bg-mut px-2.5 py-1 text-[11px] font-semibold text-muted-fg">
              Step {step} of 2
            </span>
          </div>
        </DialogHeader>

        {/* Body */}
        <div className="px-6 py-5">
          {step === 1 ? (
            <Step1
              mode={mode}
              loading={loading}
              query={query}
              setQuery={setQuery}
              students={filteredStudents}
              programmes={filteredProgrammes}
              onPickStudent={(s) => {
                setChosenStudent(s);
                setStep(2);
              }}
              onPickProgramme={(p) => {
                setChosenProgramme(p);
                setStep(2);
              }}
            />
          ) : (
            <Step2
              student={stuFinal}
              programme={progFinal}
              blockMsg={blockMsg}
            />
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between gap-2 border-t border-border bg-mut/30 px-6 py-4">
          {step === 2 ? (
            <Button
              variant="ghost"
              onClick={() => setStep(1)}
              disabled={submitting}
              className="text-[13px]"
            >
              ← Pick a different {mode === "from-programme" ? "student" : "programme"}
            </Button>
          ) : (
            <span />
          )}
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            {step === 2 && (
              <Button
                onClick={handleConfirm}
                disabled={submitting || !!blockMsg || !stuFinal || !progFinal}
                className="bg-gold text-black hover:bg-gold/90 font-semibold"
              >
                {submitting ? "Enrolling…" : "Confirm enrolment"}
              </Button>
            )}
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}

/* ---------- Step 1 ---------- */

function Step1({
  mode,
  loading,
  query,
  setQuery,
  students,
  programmes,
  onPickStudent,
  onPickProgramme,
}: {
  mode: Mode;
  loading: boolean;
  query: string;
  setQuery: (v: string) => void;
  students: StudentRow[];
  programmes: ProgrammeRow[];
  onPickStudent: (s: StudentRow) => void;
  onPickProgramme: (p: ProgrammeRow) => void;
}) {
  const placeholder =
    mode === "from-programme"
      ? "Search students by name..."
      : "Search programmes...";

  return (
    <div className="flex flex-col gap-3">
      <div className="relative">
        <Search
          size={14}
          className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-fg"
        />
        <Input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={placeholder}
          className="pl-9 h-9 text-[13px]"
          autoFocus
        />
      </div>

      <div
        className="flex flex-col gap-1 overflow-y-auto"
        style={{ maxHeight: 300 }}
      >
        {loading ? (
          <div className="py-10 text-center text-[12px] text-muted-fg">Loading…</div>
        ) : mode === "from-programme" ? (
          students.length === 0 ? (
            <EmptyRow text={query ? "No students match" : "No eligible students"} />
          ) : (
            students.map((s) => (
              <button
                key={s.id}
                type="button"
                onClick={() => onPickStudent(s)}
                className="flex items-center gap-3 rounded-[10px] text-left transition hover:bg-mut"
                style={{ padding: "12px 14px" }}
              >
                <span
                  className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[12px] font-bold text-black"
                  style={{
                    background: "linear-gradient(135deg, var(--gold), var(--gold-glow, var(--gold)))",
                  }}
                >
                  {initials(s.full_name)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="text-[13px] font-semibold text-fg truncate">
                    {s.full_name}
                  </div>
                  {s.full_name_zh && (
                    <div className="text-[11px] text-muted-fg truncate">
                      {s.full_name_zh}
                    </div>
                  )}
                </div>
                <ChevronRight size={14} className="text-muted-fg shrink-0" />
              </button>
            ))
          )
        ) : programmes.length === 0 ? (
          <EmptyRow text={query ? "No programmes match" : "No eligible programmes"} />
        ) : (
          programmes.map((p) => {
            const full = p.enrolled_count >= p.capacity;
            return (
              <button
                key={p.id}
                type="button"
                onClick={() => onPickProgramme(p)}
                className="flex items-center gap-3 rounded-[10px] text-left transition hover:bg-mut"
                style={{ padding: "12px 14px" }}
              >
                <span
                  className="h-2 w-2 shrink-0 rounded-full"
                  style={{ background: p.brand_color }}
                />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="text-[13px] font-semibold text-fg truncate">
                      {p.title}
                    </span>
                    <CategoryBadge category={p.category as Category} />
                  </div>
                  <div className="text-[11px] text-muted-fg mt-0.5">
                    Ages {p.age_range}
                  </div>
                </div>
                <span
                  className={cn(
                    "shrink-0 text-[11px] font-semibold tabular-nums",
                    full ? "text-danger" : "text-muted-fg",
                  )}
                >
                  {p.enrolled_count}/{p.capacity}
                </span>
                <ChevronRight size={14} className="text-muted-fg shrink-0" />
              </button>
            );
          })
        )}
      </div>
    </div>
  );
}

function EmptyRow({ text }: { text: string }) {
  return (
    <div className="py-10 text-center text-[12px] text-muted-fg">{text}</div>
  );
}

/* ---------- Step 2 ---------- */

function Step2({
  student,
  programme,
  blockMsg,
}: {
  student: StudentRow | null;
  programme: ProgrammeRow | null;
  blockMsg: string | null;
}) {
  if (!student || !programme) {
    return (
      <div className="py-8 text-center text-[12px] text-muted-fg">
        Missing selection.
      </div>
    );
  }
  const today = new Date().toLocaleDateString("en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
  return (
    <div className="flex flex-col gap-4">
      <div className="rounded-[12px] border border-border bg-card p-4 flex flex-col gap-4">
        {/* Student row */}
        <div className="flex items-center gap-3">
          <span
            className="grid h-9 w-9 shrink-0 place-items-center rounded-full text-[12px] font-bold text-black"
            style={{
              background: "linear-gradient(135deg, var(--gold), var(--gold-glow, var(--gold)))",
            }}
          >
            {initials(student.full_name)}
          </span>
          <div className="min-w-0">
            <div className="text-[11px] uppercase tracking-wide text-muted-fg">
              Student
            </div>
            <div className="text-[13px] font-semibold text-fg">
              {student.full_name}
              {student.full_name_zh && (
                <span className="ml-2 text-[11px] font-normal text-muted-fg">
                  {student.full_name_zh}
                </span>
              )}
            </div>
          </div>
        </div>

        <div className="h-px bg-border" />

        {/* Programme row */}
        <div className="flex items-start gap-3">
          <span
            className="h-3 w-3 mt-1.5 shrink-0 rounded-full"
            style={{ background: programme.brand_color }}
          />
          <div className="min-w-0 flex-1">
            <div className="text-[11px] uppercase tracking-wide text-muted-fg">
              Programme
            </div>
            <div className="text-[13px] font-semibold text-fg">{programme.title}</div>
            <div className="flex items-center gap-2 mt-1.5">
              <CategoryBadge category={programme.category as Category} />
              <span className="text-[11px] text-muted-fg">
                {fmtPeriod(programme.period_start, programme.period_end)}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* Meta rows */}
      <div className="grid grid-cols-2 gap-3 text-[12px]">
        <div className="rounded-[10px] border border-border p-3">
          <div className="text-[11px] uppercase tracking-wide text-muted-fg">Cost</div>
          <div className="text-[13px] font-semibold text-fg mt-0.5">
            Included in plan
          </div>
        </div>
        <div className="rounded-[10px] border border-border p-3">
          <div className="text-[11px] uppercase tracking-wide text-muted-fg">
            Start date
          </div>
          <div className="text-[13px] font-semibold text-fg mt-0.5">{today}</div>
        </div>
      </div>

      {blockMsg && (
        <div className="rounded-[10px] border border-danger/40 bg-danger/10 px-3 py-2 text-[12px] font-semibold text-danger">
          {blockMsg}
        </div>
      )}
    </div>
  );
}

/* ---------- Trigger button helper (optional sugar) ---------- */

export function AddEnrolmentButton({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="inline-flex items-center gap-1.5 rounded-full border border-gold/40 px-3 py-1.5 text-[12px] font-semibold text-gold transition hover:bg-gold/10"
    >
      <Plus size={14} /> Add enrolment
    </button>
  );
}

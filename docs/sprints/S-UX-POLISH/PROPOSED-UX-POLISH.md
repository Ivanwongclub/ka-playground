# PROPOSED — S-UX-POLISH · the deferred design/visual-polish phase (pre-discussion record)

> **Documentation only — NOT a build card.** This is the record that will LEAD the future polish
> discussion: it captures the observations Leo has made about the current admin surfaces, and turns each
> into a **design question with decisions to make**, so that conversation starts from material rather than
> complaints. Nothing here is scheduled or scoped for build yet. It is a **living list** — append
> observations as they surface, until the polish phase actually opens with its own think-first.

---

## 1. Why this is deferred (deliberate, not a backlog miss)

The UX phase so far has been **functional-first by design**: prove the money / consent / 成團 / storefront
logic works and is safe, surfaced through the shared **S-UX2a display kit + design tokens** — deliberately
NOT polished into designed flows. That order is correct, for three concrete reasons:

- **A polished wizard over a broken consent gate is worthless.** Visual polish on top of unproven
  behaviour is the wrong risk to buy down first; the invariants (BI-9, BI-6, the 成團 gate, the storefront
  leak test) had to be provably right before the screens are made beautiful.
- **Polishing now means touching the same screens 3×.** Imagery (S-MARKETPLACE-A's deferred sub-step),
  Member surfaces, and any re-theme all still land on these surfaces. A per-screen tweak now is re-done
  when each of those arrives — wasted work, and incoherence in between.
- **One coherent design-system pass beats scattered per-screen fixes.** The issues below (trilingual
  input, wizard flow, visual density) are **cross-cutting** — they recur on every trilingual surface and
  every wizard. Deciding them once and applying everywhere is cheaper and more consistent than fixing the
  programme wizard in isolation.

So this is a **deliberate deferral** to after the functional surfaces substantially exist — not an
oversight. Mid-build one-off polish is explicitly declined in favour of this later, coherent pass.

---

## 2. Observed issues (concrete, from real screens)

From the admin programme wizard (`AdminProgrammes.tsx`) especially, and cross-cutting where noted. This
list is **open** — append as more are noticed.

- **Trilingual input is a wall of inputs.** Every trilingual field renders EN + 繁 + 简 stacked in the
  same form (marketing section: 4 fields × 3 languages = 12 inputs in one drawer; consent templates and
  programme names the same). This is **correct for data-integrity** — you see at a glance what is empty,
  and the completeness gate is legible — but it is **poor UX** (dense, repetitive, no focus). Crucially it
  **repeats across EVERY trilingual surface** (consent templates, marketing, programme names, team/role
  names) — so it is a **cross-cutting design-language decision, not one screen's problem**.
- **No visual hierarchy / no imagery.** The admin surfaces are dense forms with little to guide the eye —
  no hero, no sectioning beyond a flat list, minimal use of type scale or spacing to signal importance.
- **No guided flow.** The programme wizard is a **flat list of 11 sections**, each opened via its own
  drawer, with no progressive disclosure, no stepper, no "where am I / what's next / how much is left."
  Readiness is a number ("complete/required") rather than a felt sense of progress.
- *(append here as observed — e.g. table density, empty states, error placement, mobile layout, dark-only
  contrast on long forms, the Details/Mark-complete verb choices, …)*

---

## 3. The design questions each issue raises (decisions for the polish discussion)

Framed as decisions so the future conversation chooses, rather than re-notices:

- **Trilingual input — decided ONCE, applied everywhere.**
  - Tabbed languages (EN | 繁 | 简 tabs per field/section)?
  - One primary language + collapsible others (EN always visible, 繁/简 expand)?
  - Side-by-side columns?
  - A **"copy from EN" / machine-translate affordance** to seed 繁/简 then edit?
  - Whatever is chosen becomes the design-language for ALL trilingual surfaces — the completeness/at-a-
    glance-empty property must survive the redesign (it is a real data-integrity affordance, not just
    visual).
- **Wizard flow.**
  - A **stepper with progress** vs the current flat list?
  - **Grouped sections** (e.g. "Basics", "Teams & Tracker", "Money", "Storefront") with progressive
    disclosure?
  - **Save-and-continue** linear flow vs the current open-each-drawer hub-and-spoke?
  - How does **preflight / readiness** surface as felt progress rather than a raw count?
- **Visual language.**
  - Where do **visuals / imagery** live (programme hero, category art, member/event imagery)? — ties to
    S-MARKETPLACE-A's deferred imagery sub-step.
  - What is the **density target** (compact admin vs airier public storefront)?
  - How does the **design system extend beyond the current tokens** (components, layout primitives, empty
    states, motion) — Design System v2.1 is the binding baseline; the polish pass proposes its evolution.
- **Scope boundary — which surfaces are IN the polish pass.**
  - **Admin cockpit** (wizards, queues, reports) — the densest, most-observed?
  - **Public storefront** (S-MARKETPLACE-B) — client-facing, highest visual bar?
  - **Member / guardian** surfaces — once they exist?
  - Which surfaces stay **functional-only** for now (internal audit/reconciliation views)?

---

## 4. When to start it + why it needs its own think-first

**Start after the functional UX surfaces substantially land** — roughly **post-S-MARKETPLACE-B + Member
surfaces** — because:

- Polishing needs a **complete set of screens to design against.** Designing the trilingual pattern or the
  wizard flow against a half-built surface set means redesigning again when the rest arrives.
- The two headline moves — **"improve trilingual input everywhere"** and **"add a guided wizard flow"** —
  are **cross-cutting design decisions**, not single-screen edits. Applying them touches many surfaces at
  once.

Therefore the polish phase **gets its own think-first / design-direction pass** (a PROPOSED that chooses
the §3 decisions and defines the design-language deltas), and is **NOT a single build card** — it is a
mini-phase (likely several cards: the trilingual pattern, the wizard flow, the storefront visual pass,
etc.), sequenced after its design direction is agreed.

---

## 5. Client-framing note

Some current plainness is **intentional for the demo-viable milestone**. If these screens go in front of
the client **before** the polish pass, frame them as **"functional now — the visual design pass is a
planned phase,"** not as the finished look. The money/consent/成團/storefront **logic** is the milestone
being demonstrated; the **visual design** is a deliberately-later, coherent phase. Do not let a dense
admin wizard read as the intended final product.

---

## Status

**Pre-discussion record — open/living.** No code, no card, no schedule. Owner: Leo, to open the polish
phase (with its own think-first) once the functional surfaces substantially exist. Append observations
here in the meantime.

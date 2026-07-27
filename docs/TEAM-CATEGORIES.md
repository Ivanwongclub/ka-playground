# KA Playground — Team Category Mechanism (Clarification)

**Purpose:** hand this to any Claude chat so it understands exactly how team categories work. This corrects earlier misreadings. Where any other document disagrees, **this file wins.**

---

## 1. What a category is

A team category is a **formation lobby**: an admin-configured grouping under which teams are created, and within which students see each other's forming teams in real time.

**It is NOT a fixed system taxonomy.** The original requirements outline mentioned "School Team or Armour Team" — those were **example labels the admin might create, not built-in types**. There is no hard-coded "school type" or "armour type" anywhere.

A category can be anything the admin needs:
- One lobby **per participating school** ("St. Paul's Co-educational College", "Bright Future Academy")
- An **"Armour Academy"** lobby for students invited directly by the academy
- **Per programme cohort** ("Summer 2026 — Cohort A")
- **Per region** ("Hong Kong", "Singapore")
- Any other grouping

## 2. Why it exists

Team formation needs a boundary: which students form teams *together*? Without lobbies, every enrolled student across every school and region sees every team — wrong for both privacy (UHNW client families) and practicality (a school's teacher approves their own school's teams). The lobby is that boundary. Students browse, join and form teams **inside their lobby**; a team belongs to exactly one lobby for its whole life.

## 3. Data model

`team_categories` — admin-created lookup rows, **never an enum, never hard-coded**:

| Column | Meaning |
|---|---|
| `id`, `programme_id` | Lobbies are per programme |
| `name_en / name_tc / name_sc` | Trilingual display names — renameable any time, no migration |
| `description` | Shown to students at lobby entry |
| `school_id` (nullable) | **Optional school binding** — see §5 |
| `assignment_rule` | `auto_by_school` \| `open` \| `admin_assigned` — see §4 |
| `is_default` | Exactly one default lobby per programme |
| `active`, `sort_order` | Retire without deleting; display order |

`teams.category_id` → one team, one lobby. Adding, renaming or retiring a lobby is an admin action in the programme wizard (Team Rules section) — no code change, no migration.

## 4. How students land in a lobby

Evaluated at team-formation entry, per category's `assignment_rule`:

| Rule | Behaviour |
|---|---|
| `auto_by_school` | Student holding an active link to the lobby's bound school is routed in automatically |
| `open` | Student may choose this lobby at formation entry |
| `admin_assigned` | Only an admin places students here |

Resolution order:
1. All `auto_by_school` lobbies are checked against the student's school links
2. **Exactly one match** → student lands there, no choice shown
3. **No match** → student lands in the programme's `is_default` lobby (or chooses among `open` lobbies if several exist)
4. **Multiple matches / multiple open options** → student is presented the choice, with each lobby's description
5. Admin can always reassign manually (audit-logged)

**Single-lobby programmes:** if a programme has only one active category, the category step is invisible — students never see a choice that doesn't exist.

## 5. School binding (the only constraint a lobby can carry)

If `school_id` is set, only students with an **active link to that school** can create or join teams in the lobby.

> **SUPERSEDED IN PART (S05-1, 2026-07-27, Leo-ratified):** the inline reason *"This lobby is for
> students of X — you are not linked to X"* is NOT shown to a non-linked student. The S02B
> partner-roster scoping (team_categories RLS) hides school-bound lobbies from non-linked students
> entirely — naming X would disclose that X is a partner. So a non-linked student never sees the
> bound lobby (their `/lobbies` omits it), and a direct API POST to it is refused generically
> ("that lobby does not belong to this programme"). The roster-confidentiality decision wins over
> this UX nicety. §5's binding CONSTRAINT stands in full; only the disclosing message is dropped.

If `school_id` is null, the lobby is open to any enrolled student regardless of school.

This is how "School Team" behaviour is achieved *when wanted* — by binding — not by a special type.

## 6. Visibility scoping

Team visibility has three levels (programme default, configurable): `public` (all enrolled students in the programme), **`category` (same lobby only — the lobby wall)**, `private` (invite only). The `category` level is defined by this mechanism: your lobby-mates see your forming team; other lobbies don't.

## 7. Worked examples

**Example A — school-partnered programme:**
Categories: "St. Paul's" (school-bound, auto_by_school) · "Bright Future" (school-bound, auto_by_school) · "Armour Academy" (unbound, is_default). School-linked students are auto-routed to their school's lobby; directly-invited students land in Armour Academy. Each school's teacher approves teams in their own lobby.

**Example B — direct-only elite programme:**
One category: "Global Elite 2026" (unbound, is_default). Students never see a category step at all.

**Example C — regional programme:**
Categories: "Hong Kong", "Singapore", "Toronto" — all unbound, all `open`. Students choose their region at formation entry.

## 8. Edge cases (already decided)

- **Category retired mid-formation:** existing teams keep it (retired ≠ deleted); no new teams can be created in it
- **Student's school link revoked after joining a bound lobby:** membership stands; flagged in the teacher's exception queue for decision
- **Team never changes lobby** — if a team must move, it is disbanded and re-formed (audit clarity beats convenience)
- **Cross-lobby joining:** not possible; a student wanting a team in another lobby must qualify for that lobby (§4–5)
- Whether a student's *eligibility* is walled per lobby is a per-programme flag; the default is that the lobby constrains team composition, not student eligibility

## 9. What this replaces

Any earlier text stating categories are "enrolment-channel types", "School Team = partner school students, Armour Team = direct students" as fixed system semantics, or a two-row seed of `school`/`armour` — all superseded. Those descriptions treated example labels as architecture. The architecture is: **admin-defined lobbies, optional school binding, per-lobby assignment rules.**

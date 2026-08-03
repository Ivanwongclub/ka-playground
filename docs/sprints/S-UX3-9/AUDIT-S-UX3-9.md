# AUDIT KAP-S-UX3-9 — Guardian / teacher self-service (My Children · My Payments · My Students)

**Result:** PASS · **Date:** 2026-08-04 · **HEAD at gate:** `e2c2330`

> Written by Claude Code at the card's end. Honesty outranks looking good. This is the BUILD audit; the
> in-product surfaces are My Children / My Payments / My Students. Planning: `PROPOSED-SELF-SERVICE.md` +
> `CARD-S-UX3-9.md` (this dir). Does NOT rewrite any prior AUDIT. **This card closes the existing-user run.**

## 0. Scope

The nav map's **gap C** — three surfaces the engines already served but had no door:
- **Guardian My Children** — a child-centric view of enrolments + consent + sessions.
- **Guardian My Payments** — the authenticated money view (obligations + receipts + payment links).
- **Teacher My Students** — the teacher's school roll.

**ONE batched card, MIXED DEPTH.** Almost entirely display over built/existing-RLS reads; the single piece
of new backend — one teacher read — was reviewed **line-by-line** (it returns minor identities), the three
UIs **frontend-scan**.

## 1. The one new read — teacher `GET /teacher/students` (line-by-line)

**The gap:** `/students` was a `notImplemented` stub. **The relationship:** `teacher_links = (teacher_id,
school_id)` — a teacher links to a SCHOOL, not to individual students — so a teacher's students are their
school's roll (via `school_links`).

**ELEVATION-FREE, by design (the reviewed premise):** `school_links_read` admits
`role IN ('school_admin','teacher') AND school_id ∈ app.school_ids`, and `users_read`'s teacher clause
admits those students' names. So the read rides the teacher's OWN RLS — it **crosses no wall** (unlike the
S-UX3-4 attendance roster, which had to cross the users wall because session attendees span schools). A test
asserts `TeacherStudentsController::index` is **NOT** in the elevation allowlist; `ScopeElevationTest` green.

**The child-data tooth:**
- **Exact allowlist `{student_id, student_name}`** — nothing else leaves.
- **Withheld:** guardian identity, consent, enrolment detail, money, another school's students (the last
  enforced by RLS, not just the query — `enr_read` doesn't even admit a teacher).
- **Boundary proven both directions:** a teacher sees their own school's roll; **another school's students
  are denied/absent**; a teacher of school B sees a **disjoint** roll from school A. Cross-school
  child-privacy, tested.

## 2. Guardian reads — built RLS, one reused elevation, money read-only

**My Children** composes built reads under the guardian's own RLS (never another guardian's child):
- enrolments (`enr_read` — `student_id ∈ app.student_ids`), grouped by child;
- a **consent chip REUSING the existing `derivedStatus` elevation** (S-UX3-3b, already allowlisted) — **not a
  new elevation**;
- a link to the child's sessions (`/family/sessions`, S-UX3-4, child-guard-first).

**My Payments** — the OD-67 family-money reads, **READ-ONLY**:
- `/orders` (`familyRead`), `/orders/{id}/lines` + `/receipts` (`viaOrder`), `/my/payment-links` (own).
- **`token_hash` never reaches the client** — the reads don't select it; asserted absent from both the
  orders and links responses.
- Shown: programme / amount / status / due / receipt. No mutate in the view.

**The mint-link button** (existing `POST /my/orders/{id}/payment-link`) — **mints, does not pay:** it returns
`{url}` (the forwardable `/pay` page); a test proves the order stays `issued` with no receipt (money not
moved). Framed **"Get payment link"**, never "pay". Its refusal is **shown-not-hidden** — the button stays
visible and the server's 422 renders (the risk shot: an issued-but-past-due order → *"The payment deadline
has passed"*).

## 3. Honest deviations

| Item | Note |
|---|---|
| **School-payer: display filter, NOT RLS** | The ruling's premise ("school-payer orders excluded") is an RLS overstatement. `familyRead` keys on `student_id`, so a school-payer order **for the guardian's child IS RLS-visible** — a test asserts exactly this. The exclusion is a **My Payments display filter** (`payer_party ∈ {guardian, student}`), showing only what the family pays. The real privacy line — **another family's orders absent** — holds in RLS and is tested. |
| **`/my/students` → `/teacher/students` rename** | The read was first routed at `GET /my/students`, which collides with the **retired guardian-create path** (OD-27): `OnboardingTest` asserts `POST /my/students → 404`, and a GET route there turned the POST into 405. **The full suite caught it (1 red); fixed inside the step** by renaming to `/teacher/students` (no shared prefix with the retired path or the guardian `/my/students/{id}/…` reads). Browser route stays `/my/students`. The suite working as designed. |
| **`mutate()` extended** | gained an optional `data?` (parsed success body) so the mint flow reads the `{url}`. Backward-compatible — existing callers ignore it. |
| **Teacher roster is SCHOOL-scope** | v1 = what `users_read`/`school_links` grant (the school roll). A finer "students I personally teach" (programme/team-scoped) is a later refinement (PROPOSED §7). |

## 4. Exit gate

```
$ php vendor/bin/phpunit tests/Feature/SelfServiceUxTest.php tests/Feature/OnboardingTest.php tests/Feature/ScopeElevationTest.php
OK (18 tests, 63 assertions)
    teacher tooth: allowlist {student_id,student_name} + forbidden-field red-green sweep
    cross-school denial BOTH directions (A's teacher ⊥ B's roll)
    teacher read adds NO elevation (controller absent from scope-elevations)
    guardian: own family orders only; another family ABSENT; token_hash NEVER (orders + links)
    mint returns {url} and does NOT move money; refuses a non-issued order (422)
    OnboardingTest: POST /my/students still 404 (retired-path guard — the collision fix)

$ npx tsc --noEmit                          → clean
$ npm run i18n:check                         → 675 / 675 / 675, parity complete
$ npm run build                              → bundle-budget PASSED
$ php artisan test --exclude-group=clamav    → 493 passed (6091 assertions)
$ php artisan reconcile:run                  → 58 / 58 (before AND after the risk-shot teardown)
$ php artisan migrate --pretend              → Nothing to migrate (0 new)
```
**Verdict:** **PASS.** Battery **58/58**; **NO new elevation** (the teacher read rides existing RLS; the
guardian consent chip reuses `derivedStatus`); **migrations 0** (all reads ride existing tables).

## 5. Invariant check

| BI / discipline | Touched? | Evidence |
|---|---|---|
| Child-data read boundary | **yes** | teacher roll: tight allowlist + cross-school denial (both directions); guardian reads scoped to own children |
| Scope-elevation discipline | **none added** | `TeacherStudentsController::index` asserted absent from the allowlist; `ScopeElevationTest` green |
| Money reads (OD-67) | reused, read-only | `familyRead`/`viaOrder`; `token_hash` never selected; no mutate in the view |
| BI-2 / money integrity | reused | mint mints a link, moves no money (order stays issued, no receipt) — tested |
| Provenance battery | protected | risk-shot demo data torn down via psql (system context); reconcile re-confirmed 58/58 |

## 6. Hand-offs forward
- **Re-seed for walkable surfaces:** a teacher with a `teacher_link` + school students; a guardian with a
  child, an issued order (and a past-due one to exercise the mint refusal). The risk-shot demo data was torn
  down.
- **Operations-only teacher-roster / finer "students I teach"** — later refinements (PROPOSED §7).
- **The existing-user run is COMPLETE.** Next is the **S-UX-POLISH** phase (its own phase: design audit +
  direction + 2–3 prototyped anchor screens, schema-driven, all surfaces) — awaiting Leo's push+tags and the
  reviewer's design-audit think-first. No new build work starts here.

# PROPOSED S04C REVIEW — think-first reconciliation (no code)

> Planning artefact for Leo's eyes-on review **before STEP 1**. Nothing built; nothing committed
> beyond this document. Every claim below was checked against the running repo this session
> (file:line cited), not against the card or memory.
> Card under review: `docs/sprints/S04C/SPRINT.md` (Self-registration & the approval queue, OD-23).

## Verdict in one paragraph

The card's **intent** is correctly Model B and matches OD-23/27/28/29 and the 2026-07-26 repo
reconcile. But the card is written as if it **reuses a shipped "S06B design"** — and that design was
**never built**: the S06B card was deleted (change-log row 12), and the two artefacts the card leans
on hardest — a **`public` scope context** and an **anonymous-WRITE RLS policy** — **do not exist in
the codebase**. So STEP 1 is not "reuse verbatim"; it is a **greenfield build** of the platform's
first anonymous write surface. That is the headline correction. Six further drifts and **four
decisions for you** are below. The card's step *shape* is sound and I recommend keeping its four
steps, with the sharpening in §7.

---

## 1. Scope reconciliation — does the card match Model B as reconciled?

**Yes, on intent.** The card implements: students & guardians self-register through the only
anonymous write; **approval creates the account**; a registration naming a counterpart is one queue
item carrying **two genuine, separately-audited decisions** (OD-23 point 6, "approving a PERSON is
not approving a RELATIONSHIP"); guardian↔student linking is admin-approved; the S01
guardian-creates-student path is retired (OD-27). This is faithful to OD-23 (`OPEN-DECISIONS.md:36`),
OD-27 (`:37`), OD-28 (`:38`), OD-29 (`:39`) and REPO-RECONCILE Ruling 4 / card-review confirmation 1.

**Retired-path reconciliation confirmed against code.** The path OD-27 retires is live and reachable
today: `POST /my/students` → `OnboardingController::createStudent` (`routes/api.php:76`), backed by
`GuardianStudentService::createStudent` (`app/Services/Identity/GuardianStudentService.php:27`) with
its allowlist entry at `config/scope-elevations.php:15`. The system audit observed it still routes
(SYSTEM-AUDIT §Surprise 1, IA-10). S04C removes all three (endpoint, service entry, allowlist line).

**One concrete reuse-vs-reality gap (D2, below):** OD-27 *retains the underlying system-context
creation primitive* for approval to reuse — but that primitive as built creates accounts **verified**
(`GuardianStudentService.php:42` → `'email_verified_at' => now()`, "guardian-led creation vouches the
account"). OD-29 requires approval to create the account **UNVERIFIED** and send the 2.11 link. So the
"retained primitive" cannot be reused verbatim; it needs an unverified-creation variant.

## 2. FLAG #2 (mandated gate) — is link activation audited `to_state='active'`?

**The dependency is real, load-bearing, and NOT YET honoured by the card's prose.** S06 AUDIT §8 flag
#2 (`docs/sprints/S06/AUDIT.md:79`): the requires_all consent hardening reads "active-as-of-confirm"
from `guardian_link` audit events with `to_state='active'`; a new onboarding path that creates a link
without that audit "would read its guardians as **not-active** — a false green." The consumer is
concrete: `ConsentCompleteAtConfirmAssertion.php:69` keys on
`entity_type='guardian_link' AND to_state='active'`.

**Where activation happens in S04C:** not at registration approval, but at the **link-approval
decision** (card STEP 4's second decision). Both link origins converge there — the existing-verified
counterpart (pending link) and the materialised held link (form-claimed pending link) are each
*activated* by an admin's link decision. **That endpoint must emit
`action='guardian_link.created'` (or a lifecycle transition), `to_state='active'`, carrying the
approver's identity** — matching the two existing active-link call sites
(`GuardianStudentService.php:54` and `LinkController.php:134`, school-vouch).

> Note the S06 flag text names `GuardianStudentService` + `PairingService` as the two paths; in the
> code the two paths that write an **active** link are `createStudent` and `schoolVouch`
> (`PairingService` audits `guardian_link.requested`, not active). Write the S04C assertion against
> reality, not the flag's prose.

**The card does not currently state this audit in any step.** It must, before code. **Recommendation
(D-iv):** don't rely on convention — add a structural S04C assertion **`links.activation_audited`**
(every `active` guardian_link has a `to_state='active'` audit event) with red-then-green teeth. That
closes the S06 dependency by proof, not by a code-review promise, and it will catch S04D/S04E too.

## 3. The anonymous WRITE surface — first of its kind; what's the confinement?

Self-registration is the platform's **first anonymous WRITE** (S04B's payment link was anonymous
READ). The confinement machinery the card names is right, but half of it is **greenfield, not reuse**:

| Control | Status in repo | Note |
|---|---|---|
| `public` scope context | **NOT BUILT** | `app.context` has exactly two values, `system` and `request` (`ScopeContext.php:69,80`). No `public` case anywhere. STEP 1 must build it. |
| Anonymous-WRITE RLS policy | **NOT BUILT** | Every write policy is gated on `system` or a concrete authenticated actor (`2026_07_24_210000_scope_layer_rls.php:56`). The only all-context INSERT is `audit_events WITH CHECK(true)` (BI-8). S04B's anonymous payment write runs **inside `asSystem()`** — a system write, not a `public`-context write. |
| Constant-shape response / no enumeration | pattern exists (payment-link `null` 404, `PaymentLinkController.php`) | Card's 202 + opaque ref + no status endpoint is correct; see the oracle warning below. |
| Throttle | **built** (named limiters, `AppServiceProvider.php:41`) | Add a `registration` limiter mirroring `throttle:payment-link` (10/min by ip). |
| Honeypot / min-fill-time | **NOT BUILT** (zero hits repo-wide) | Greenfield. |
| Global failure ledger (abuse backstop) | precedent exists (`pairing_code_failures`, hard-invalidate after 10 global) | Optional pattern to mirror. |

**Attack-surface flags:**
- **`scope.public_context_confinement` presupposes a `public` context that must first be built** — the
  assertion ("`public` in exactly one INSERT policy, nowhere else") is only meaningful once the context
  exists. STEP 1 builds the context *and* the assertion together.
- **The subtlest enumeration oracle is the counterpart-email field**, not the registrant's own email.
  If naming an existing/verified counterpart produces observably different behaviour (a pending link
  forms) than naming an unknown one (a held link forms), a probe distinguishes "this address is a
  registered guardian" from "not." The registrant-facing response **must be byte-identical** either
  way (the held-vs-pending divergence happens server-side, invisible to the requester, and surfaces
  only in the approver's queue). Add this to the constant-shape probe set explicitly.
- **Decide the write mechanism (part of D-iii):** either (a) follow the payment-link precedent and run
  the registration INSERT inside a **declared `asSystem()` elevation** (fast, proven, but then the
  INSERT is a *system* write and the `scope.public_context_confinement` assertion has nothing to bind
  to), or (b) build a genuine **`public` context + one `WITH CHECK` INSERT policy** (matches the card
  and OD-23's "public context confined to one INSERT policy, structural assertion"). The card and
  OD-23 clearly intend (b). Confirm — it is the larger build.

## 4. The holding state — how is "registered but no programme access" modelled?

Per OD-28 (`OPEN-DECISIONS.md:38`) the state is **DERIVED, not stored**: zero active links =
**Registered** (login, own profile, browse the *global* published catalogue, see own pending-link
status; RLS person-scope empty → scoped reads return nothing); first approved link = **Active**. There
is no column to add — the holding state already falls out of the RLS scope layer. Good.

**But there is a real modelling gap for a self-registered STUDENT (→ D-i).** REPO-RECONCILE
confirmation 1 says programme access is gated "until an admin approves **their school**." Yet OD-28
says Active requires **an active link**, and the card's **NON-SCOPE defers "teacher/school link
states" to S04D**. A lone self-registered student who names a school and is approved would then have
an approved *registration* but **no active link** — leaving them stranded in Registered (catalogue
browse only, no enrolment) until S04D. That contradicts "school approval grants programme access."

The card ships `guardian_links.pending_approval` for **orphan guardian pairs**, but says nothing about
a **school↔student** link — which is the thing that would make a school-routed student Active under
OD-28. This must be resolved before STEP 1 (see decision D-i).

## 5. The payment-link URL bug — in scope, and mis-framed

**In scope: yes.** The card's CARRY-IN (`SPRINT.md:17`) correctly claims it, tied to the public-page
work. The bug is confirmed at `PaymentLinkService.php:65` → `'url' => url("/pay/{$token}")`, which
resolves to `http://localhost/pay/{token}` (APP_URL + path, no `/api`, no real host/port).

**But the card/audit framing ("missing the `/api` prefix") is itself questionable (→ D-ii).** A
forwardable link is for a **human third party** (a grandparent). It must open a **rendered page**, not
JSON. There is **no frontend `/pay/{token}` page in the repo** (`web/src` has none). So the correct
fix is not "add `/api`" — that would send a grandparent to a JSON body. It is: **build the public
payment page** (the anonymous, initials-only render that *calls* `/api/pay/{token}`), and **mint an
absolute URL to that page**. This makes STEP 1's "public payment page" and the URL fix one piece of
work, and it is what finally lets us capture the screenshot the system audit had to skip (SYSTEM-AUDIT
footnote, Surprise 2).

## 6. Step boundaries & the full drift register

| # | Drift / risk | Severity | Disposition |
|---|---|---|---|
| **D1** | Card says "REUSING the S06B anonymous-write RLS", "the S06B design reused verbatim" — **S06B was never built**; `public` context + anonymous-write policy are **greenfield** (§3). | **high** | Reframe STEP 1 as a build. Understand the cost. Confirm mechanism (b) in D-iii. |
| **D2** | The "retained creation primitive" creates accounts **verified** (`GuardianStudentService.php:42`); OD-29 needs approval to create **unverified**. | med | STEP 2 needs an unverified-creation variant, not verbatim reuse. |
| **D3** | FLAG #2 audit (`to_state='active'` at the link-approval decision) is **absent from every card step**. | **high (mandated)** | Add to STEP 3/4 prose + add assertion `links.activation_audited` (D-iv). |
| **D4** | Self-registered **student → Active** path unmodelled; NON-SCOPE defers school↔student links to S04D, stranding a lone student (§4). | **high** | Resolve via D-i before STEP 1. |
| **D5** | Forwardable payment URL should target a **public page** (to be built), not the API; "missing /api" is a mis-frame (§5). | med | Resolve via D-ii; fold page + URL into STEP 1. |
| **D6** | Enumeration oracle via the **counterpart-email** field, not the registrant email (§3). | med | Add to STEP 1 constant-shape probe set. |
| **D7** | OD-27 says retire "in the **same step** that ships self-registration"; card retires in STEP 4 (self-reg ships STEP 1–2). | low | Reconciled: all work lands on `main`, sprint tags at the end → no *released* gap intra-sprint. Keep STEP 4, note the rationale in AUDIT. |
| **D8** | Card calls `registration_requests` "v2 — replaces the S06B design's table" — **no v1 table exists**; it is greenfield/v1. | low | Honesty note; drop "v2/replaces". |
| **D9** | `guardian_links.status` CHECK today is `requested\|pending_confirmation\|active\|revoked\|expired\|superseded\|cancelled` (`2026_07_24_130000...:39`) — **no `pending_approval`**. | low | STEP 3 migration must **extend the CHECK** (additive), and reconcile naming with 2.30 (`pending_approval` vs existing `pending_confirmation`). |

**No STOP-condition blocker found.** No open OD gates a step (OD-23/27/28/29 all RESOLVED); no
migration that ran on staging/prod is touched; no immutable rows are edited; no new dependency is
named. The two things that must be settled before STEP 1 (D-i, D-ii) are **design rulings**, not
blockers on an open decision.

---

## Decisions for Leo (before STEP 1)

**D-i — Self-registered student → Active (the §4 gap). Recommended: (a).**
- **(a) [Recommended]** Approving a **school-routed student registration** creates a minimal
  **school↔student affiliation** — the active link that flips the student to Active under OD-28. The
  richer school-link *state machine* + teacher links stay S04D; only the minimal affiliation lands
  here. Reword the NON-SCOPE line so it defers the *state machine*, not the affiliation itself.
- **(b)** Student stays **Registered** (catalogue browse only) after registration approval until a
  guardian or school links them in S04D. Honest to OD-28 as written, but means "school-approved
  registration" grants **no** programme access in S04C — contradicts REPO-RECONCILE confirmation 1.

**D-ii — Forwardable payment link target (the §5 mis-frame). Recommended: (a).**
- **(a) [Recommended]** Build the **public payment page** in S04C and mint an **absolute URL to that
  page** (config-driven public base). Fixes the bug correctly and unblocks the screenshot.
- **(b)** Mint to `/api/pay/{token}` (literal "add /api"). Rejected — sends a human to raw JSON.

**D-iii — Anonymous-write mechanism (the §3 choice). Recommended: (b), matching the card/OD-23.**
- **(b) [Recommended]** Build a real **`public` scope context + one `WITH CHECK` INSERT policy** for
  `registration_requests`, with the structural confinement assertion binding to it. Larger build;
  matches OD-23 exactly; gives `scope.public_context_confinement` something to prove.
- **(a)** Run the registration INSERT inside a declared `asSystem()` elevation (reuse the payment-link
  precedent). Cheaper, but the INSERT becomes a *system* write and the confinement assertion is moot.

**D-iv — FLAG #2 teeth. Recommended: yes.**
Add assertion **`links.activation_audited`** — every `active` `guardian_link` carries a
`to_state='active'` audit event — with red-then-green teeth. Closes the S06 dependency structurally.

---

## 7. Proposed step plan (keep the card's four; sharpen as marked)

**STEP 1 — Public registration surface + the `public`-context substrate + public payment page.**
Build the `public` scope context and **one** confined `WITH CHECK` INSERT policy (D-iii/b) + the
`scope.public_context_confinement` assertion. Trilingual student/guardian forms (2.28 Q0); school
picker = listed partners OR direct-to-academy (first-class, no free text); optional counterpart email;
`throttle:registration` + honeypot + min-fill-time; **constant-shape 202 + opaque ref, no status
endpoint**. **Fold in the public payment page + absolute-URL mint fix (D-ii/a, D5).**
*VERIFY:* anon read sweep zero everywhere; INSERT blind (no RETURNING); confinement assertion red→green;
constant-shape probes — existing vs non-existing vs duplicate registrant email **and the
counterpart-email oracle (D6)** all byte-identical; 429 paste; **minted URL resolves + public page
renders (the screenshot the system audit skipped)**.

**STEP 2 — Approval → account creation (UNVERIFIED) + OD-29 verification.**
Approve (routed reviewer) → account via an **unverified-creation** primitive (D2 — a variant of the
retained primitive, born unverified); 2.11 single-use signed verification link; login refused before
verification; decline requires reason; **both decisions audited with actor**.
*VERIFY:* approve → account + verification-mail fixture; pre-verification login refusal paste;
post-verification login paste; decline-without-reason refusal.

**STEP 3 — Orphan pairs + held links + link-activation audit (FLAG #2).**
Named counterpart with an existing **verified** account → pending `guardian_link` into the queue;
not-yet-registered counterpart → `held_link` that materialises to a *pending* link **only on the
counterpart's own verified approval** ("form-claimed" origin); expiry job + audit. Extend the
`guardian_links` CHECK to add `pending_approval` (D9, additive). **The link-approval decision endpoint
writes `to_state='active'` + approver identity (D3/FLAG #2).**
*VERIFY:* the typo scenario (stranger registers the counterpart address → no clean pending link, ever;
materialises only form-claimed); expiry fixture → expired + reported; **`to_state='active'` audit row
pasted for every activation path**; `links.activation_audited` red→green (D-iv);
`links.no_unverified_materialisation` red→green.

**STEP 4 — The ONE queue + retirement (+ student-affiliation per D-i).**
Single per-approver queue (accounts + links, 2.28 Q4/Q5); age on every row; combined item = **two
endpoints, two decisions, two audit rows** (never one decision writing two); over-threshold age →
FR066 exceptions entry (reused, `guardian_replacement_exceptions`-style ledger — there is **no**
generic queue service to plug into, greenfield row-ledger); Access & Identity report gains queue-age +
registration funnel + held-link ledger. **If D-i = (a): wire the school↔student affiliation at
student-registration approval.** **Retire guardian-creates-student:** remove `POST /my/students`
(`routes/api.php:76`), `GuardianStudentService::createStudent`, and allowlist line
`scope-elevations.php:15` (49 → 48 entries).
*VERIFY:* combined-item flow pastes both audit rows with their own timestamps; escalation fixture lands
in FR066; retirement refusal (404) paste; elevation-list review shows the entry **GONE** (48); S01
suite migrated and green.

## Assertions (`--tag=S04C`) — card's five + one

Card's five stand (`scope.public_context_confinement`, `account.provenance`,
`links.no_unverified_materialisation`, `queue.escalation_liveness`, `held_links.expiry`) — plus
**`links.activation_audited`** (D-iv). `account.provenance` is correct as written (REPO-RECONCILE
Ruling 4 confirmed it).

---

## What I did NOT do
No code, no migrations, no schema, no commits beyond this file. No card edit — the drifts above are
*proposed* corrections for your ruling; I did not rewrite `SPRINT.md`. The four decisions (D-i…D-iv)
are yours before STEP 1.

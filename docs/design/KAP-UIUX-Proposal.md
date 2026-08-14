# KA Playground — UI/UX Proposal
## Enrolment-centric, entitlement-shaped, server-transitioned

> Grounded in **KAP-Data-Model-and-Schema.md** (cited as §n), the raw 58-migration schema, and **KAP-Color-System.md** (cited as Color §n). Reference patterns drawn from Salesforce Lightning / Education Cloud (EDA), HubSpot associations, Odoo and Dynamics — every "operator sees/moves the record" assumption re-read as "the viewer sees only their entitled reads; the server moves the record" (§1, §7.1–7.2).

> **Open questions — RULED 14 Aug (supersedes the original assumptions):** student fee visibility — *status-only; the live RLS exposes amounts, so this requires a deliberate RLS narrowing (B-18), not a "correct absence"*. **Team-project finance (budgets / expenses / fundraising) — OUT of ALL v1 family and member surfaces**; it is a separate staff/team module for later. Families and members do not see team budgets in v1 (the original "team-scoped read for members" assumption was wider than the ruling — do not build it). Co-guardian visibility — *peers; co-guardians are NOT shown to each other; disputes route to the academy*. Member persona — out of scope (unchanged). School-admin billing — read + chase **plus remittance declaration** (school declares "paid, ref #"; matching and confirmation stay finance four-eyes).

---

# Part A — The design grammar (rules every screen inherits)

Before any screen: five cross-cutting rules that fall directly out of the reference docs. Every pattern in Parts B–E is an application of these.

## A1. Pages are projections of the viewer's reads — the *iff* rule

The unit of composition is the **card**, and a card's existence contract is: **it renders if and only if the viewer's own read returned the row(s) behind it** (§7.1). This produces a three-way distinction the whole UI must honor:

| State | What renders | Example |
|---|---|---|
| **Entitled, rows returned** | The card, populated | Guardian sees child's consent card |
| **Entitled, zero rows** | The card, in an *empty state* | Ops opens a student with no enrolments yet — "No enrolments" is true information for ops |
| **Not entitled** | **Nothing.** No placeholder, no lock icon, no greyed card | A student's screen simply has no "Fees" card; a mentor's team page has no "Guardians" card |

The corollary for **deep links**: an unentitled URL resolves to *not found*, never *no access* — "no access" confirms existence, which is itself a leak (this is the read predicate expressed at the routing layer, §4). Cross-family isolation (§7.7) is then structural, not behavioral: a guardian's child-switcher *is* `app.student_ids`; there is no path to type another family's child because family personas have **no search surface at all** (§5, directory row).

**Where CRM instinct breaks:** Salesforce and HubSpot record pages assume a permissioned operator who sees a mostly-complete page with a few fields hidden. Here the page *shape itself* differs per viewer — the same student "record" is a different composition for the student, their guardian, their school admin, and ops. Design the compositions separately (Part C), don't design one page and subtract.

## A2. Aggregation is allowed; flattening is not

§2/§7.3 forbid a global "My Team" / "My Sessions" for a multi-programme student — those are properties of *one enrolment*. But **digest surfaces that aggregate entitled reads across enrolments are correct and necessary**, because urgency data (§6) spans enrolments. The line:

- **Participation surfaces** (team, sessions, tracker, results, fees) — always reached *through* a chosen enrolment; never a flat destination.
- **Digest/queue surfaces** (guardian's "3 things need you", student's "next session across my programmes") — cross-enrolment lists where **every item names its child + programme and deep-links into that enrolment's scope**. Each item is an entitled read; the aggregate adds nothing the viewer couldn't read row by row.

Guardian ceremonies (consent, payment) sit naturally at the *guardian* grain, not the enrolment grain — they are addressed *to the guardian* (§3.4, §7.4) — so guardian-level Consents/Payments queues are aggregations, not flattenings. A student-level "My Teams" would be a flattening. This distinction is load-bearing for the family IA in Part B.

## A3. Names: link iff the viewer has an entitled destination (§5)

A rendered person-name is a **link only when that viewer's reads include a record page to open**; otherwise it is plain text — same typography, no affordance. Student→mentor: text. Mentor→student: text (names-only, §7.8). Ops→anyone: link. School admin→their students: link. This is decided per *viewer×target*, at render time, from the same entitlement context — never hardcoded per screen.

## A4. The action grammar: request → consequence → server transition (§1, §7.2)

No screen ever mutates state; it **requests a transition** and the server writes it under `app.context = system` (§4). The universal action pattern:

1. **Action affordance** — a button (gold if primary; Color §1), never a drag handle.
2. **Consequence sheet** — before requesting, show what the server will do: the target state, cascades ("confirming this team also moves 5 enrolments to `confirmed`"), invariant checks the server will run (consent satisfied? minimums met? §3.3), and reversibility. Irreversible transitions (assessment **release** §3.8, payment **confirm** §3.7) escalate to a full modal with an explicit restatement and, on the staff side, a reason field (feeding `audit_events.reason`, §3.9).
3. **Outcome** — the returned new state re-renders the card; failure returns the *server's* invariant message, not a client guess.

Any board (team formation, enrolment pipeline) is **read-only occupancy**: columns show where records *are*; selecting a card opens a detail panel whose *buttons* request transitions. No drag physics anywhere — remove the affordance, don't disable it.

One deliberate exception the reference itself prescribes: **four-eyes disablement** (§3.7 — "a confirm button must be disabled for the recording officer"). That is a *write* gate on an entitled *read*, so disabled-with-reason ("You recorded this payment — a second officer must confirm") is correct there, and only there. Everywhere else, unentitled = absent (A1).

## A5. Color system applied to this domain (Color §1, §5, §6)

Gold has one job: the primary action, the active nav item, and **the current step of a journey**. That maps cleanly onto the lifecycles:

| Domain state | Rendering |
|---|---|
| Enrolment `submitted / in_pool / teamed` | **pending** slate-blue dot+word |
| Enrolment `confirmed / active` | **ok** green dot+word |
| Enrolment `withdrawn` | **neutral** gray |
| Consent `sent / viewed` | pending blue; **≤7 days to `expires_at` → warn orange**; `expired / declined` → danger red |
| Order unpaid, due soon | warn orange; overdue → danger red; paid/confirmed → ok green |
| Session `published` upcoming | pending blue; `cancelled` → danger; `completed` → neutral |
| Assessment (staff view) `graded` not `released` | warn orange — *"visible to staff only"* — this is precisely the warning-not-gold case Color §6 calls out |
| **Journey stepper current step** | **gold** — the one place gold marks state, because it marks *position*, not status |

Every status chip is dot **and** word (Color §6). Surface ladder in record pages: page = Canvas, card = Surface 1, chips/inner tiles/inputs = Surface 2, popovers/hover = Surface 3 (Color §2). One gold primary action per screen — if two actions compete, one of them isn't primary.

Trilingual EN/繁/简 throughout, with the fixed English term **"Team Formation"** (§7.10). Programme imagery = the scan-clean `banner_upload_id` row, else `brand_color` fallback — never an invented image slot (§3.2, §3.9).

---

# Part B — Information architecture per persona

Two grammars (§1): **family** (product-simple, phone-first, single column, one primary action) and **staff** (enterprise-dense, desktop CRM/ERP). The enrolment-centric drill — *person → enrolment cards → one scoped programme space with contextual sub-nav + programme switcher* — appears in both, but dressed differently.

## B1. Student — phone-first

**Bottom nav: Home · Programmes · Explore · Me.** Nothing else. No "My Team", no "Sessions" tab (§2, §7.3).

**Home** is a digest (A2): a "Next up" strip (soonest entitled `session` booking across enrolments — `starts_at` + booking status chip, §6), then one **compact enrolment card per enrolment**: programme banner/brand-color, mini journey stepper (current step gold), and at most one urgency chip (e.g. consent observed as "Waiting for your guardian" — the student *observes* consent, never signs, §3.4/§7.4, with an "Ask my guardian" nudge that sends a reminder, which is the one student-actored consent affordance the story permits: *"a student can ask; only a guardian can consent"* §3.1).

**Programmes** is the full enrolment-card list — the drill's front door. Tapping a card enters the **scoped programme space**:

- **Header:** programme name + brand color band + **programme switcher** (a dropdown listing the student's other enrolments — the switcher's contents are themselves an entitled read).
- **Sub-nav (scoped tabs):** **Journey · Team · Sessions · Tracker · Results.** Each tab exists iff its read returns (A1):
  - **Journey** — the full stepper `submitted → in_pool → teamed → confirmed → active` (§3.3), current step gold, with plain-language explanation of what happens next and *who acts* (mostly "the academy" or "your guardian" — the student's journey view is mostly observational, which the copy should own honestly).
  - **Team** — exists once `team_members` returns a row. Teammates **names-only** (§5): plain-text rows with role chips (from the roles/tracker tables §3.5), no drill, no contact. Mentor shown as plain text (A3). Team status as dot+word.
    **The lobby wall lives here pre-team:** while the enrolment is `in_pool`, this tab renders "Find a team" — the RLS explicitly grants an in-pool student reads on `forming` teams in their lobby (§4 teams read, "lobby wall"), and the insert policy lets a student **create** a team as themselves. So the two student-actored writes in the whole system — create team, request to join — live on this tab, both as request→consequence actions (A4): "Request to join" shows "The team and the academy will review your request."
  - **Sessions** — this programme's sessions + the student's booking state; book/cancel as transition requests with the booking-status vocabulary (`booked / waitlisted / cancelled / attended / no_show`, §3.6).
  - **Tracker** — read-only stage-gate occupancy (Plan · Design · Learn · Pitch · Launch, §3.5): a horizontal gate strip, passed gates green-checked, current gold, future muted. No manipulation affordance exists (A4).
  - **Results** — see the embargo pattern, E1. Appears only when the roster rule returns assessments (§4 `assessment_results`).
  - **No Fees tab** — working assumption per the doc's own flag (§3.7: *"whether a student may see even fee status is an open question — do not assume it"*). If entitlement is later confirmed as status-only, it surfaces as a single status chip inside **Journey** ("Programme fee: settled ✓"), never as an amounts card.

**Explore** — the public programme catalogue (`programmes` has no RLS, §3.2). Cards use banner-or-brand-color. The enrol CTA on a programme is deliberately **not** a student action: it renders as "Ask your guardian to enrol you" → sends the guardian a nudge (§7.4).

## B2. Guardian — phone-first, the acting persona

**Bottom nav: Home · Children · Consents · Payments · Me.**

Consents and Payments earn top-level slots because they are the guardian's *ceremonies* — guardian-actored, guardian-addressed (§3.4, §3.7), and carrying the two most decision-critical urgency fields in §6 (`expires_at`, `payment_due_at`). These are aggregations, not flattenings (A2): every row names **child + programme** and deep-links into that scope.

**Home = the action inbox.** A prioritized list of entitled outstanding items across the family, sorted by urgency: *"Sign consent for A-yan — 賽馬會計劃 — expires in 6 days"* (orange chip), *"Pay HK$1,800 for Ka-ho — Summer Programme — due 20 Aug"*. Below it, one row per child with a thumbnail of their enrolment states. An empty inbox says so warmly — an empty state here is *good news* and should read as such.

**Children** → child list (which *is* the entitlement set, A1 — no search, §5) → **the child record page** (family grammar, full anatomy in C2): identity header, **enrolment cards** (the spine), then association and ceremony cards. From a child's enrolment card the guardian enters the **same scoped programme space as the student sees for that enrolment** — Journey · Team · Sessions · Tracker · Results — because the guardian's entitlement mirrors the child's (§5: "their child's" down the whole column), *plus* the **Fees** view (amounts, line items, receipts — guardian-only, §3.7) and *plus* signing authority on Consent. Reusing the student's scoped-space composition for the guardian, with two additive cards, keeps the family grammar to one learned shape.

**Enrol (the guardian-actored origination):** from Explore/catalogue (guardians get Explore inside Children or Home, not a fifth tab) → pick programme → **pick which child** → consequence sheet: *"This will submit an enrolment, issue a consent request to you, and create a fee order of HK$X due by …"* (§3.3, §3.4, §3.7 — the enrolment cascade is exactly the thing to preview, A4) → request. The new enrolment card appears at `submitted`, pending-blue.

**Consents tab** → queue of `consent_requests` in `sent/viewed`, each with the expiry countdown; tapping opens the **signing ceremony**: full template text in the guardian's chosen language (the server records `language` actually rendered, §3.4), scroll-to-end gate, affirmation, signature capture — the strictest write in the system, signer-only (§3.4), and the UI should feel appropriately ceremonial: this is a screen where slowing the user down is the design goal. Signed items show the immutable evidence summary (template version, date — from `consent_signatures`, which the signer can read, §4).

**Payments tab** → orders with line items and `payment_due_at` (§6), pay via payment link; receipts listed with their sequence numbers. Refund/withdrawal state appears here when a withdrawal exists — with the **refund-window band shown before the guardian requests withdrawal**, not after (§6, E-list: "is a full refund still available" is the decision evidence; the withdrawal consequence sheet leads with it).

## B3. Mentor / Teacher — enterprise-dense, narrow

Mentors are staff-grammar but tightly scoped: **My Teams · My Sessions · Grading (iff assigned grader) · Me.**

- **My Teams** — one card per active `team_teacher_link` (§4). A team card opens Roster · Tracker · Sessions — **but Roster and Tracker exist iff the programme's `mentor_team_access` flag is on** (§3.2, §7.8); with the flag off, the team card still exists (the link row is entitled) but contains only team name, programme, and the mentor's own sessions. When on: roster is **names-only** (E4) and tracker is read-only occupancy (`stage_gates` read includes the active mentor, §4).
- **My Sessions** — sessions where `mentor_id` = self, in a today/upcoming layout. Session detail carries the booking roster and the one mentor-actored transition: **attendance marking** (`attended / no_show`, §3.6) — a per-row transition request, not a toggle, because attendance is a child-safety record; the consequence framing can be lightweight (inline confirm) but the write is still a server transition.
- **Grading** — where the mentor is an assessment's grader they see **all states** (§3.8). Every grading surface carries a persistent banner: *"Not visible to families until released"* — warn-orange, per A5's `graded`-not-`released` mapping. Grading enters scores; **release is not the grader's action** (it belongs to academy staff, E1).

This deliberately gives mentors *no* people surface, no guardian visibility, no cross-team browsing (§5: directory = own team only).

## B4. School admin — enterprise-dense, school-scoped

**Nav: Overview · Students · Teams · Sessions · Consents · Billing.** Everything scoped to `app.school_ids` (§4).

- **Overview** — school KPIs computed from the admin's own entitled reads (A1 applies to *counts* too — a number on screen is a count of rows this viewer can read, §7.1): active students, enrolments by stage (funnel), consents expiring ≤7 days, invoices aging.
- **Students** — the school's roster (active `school_links`, §4) with real drill: names link (A3) to a school-scope student projection (C3): enrolments, team, sessions, **released** results only (§5).
- **Consents** — the **chase queue** (§4 grants school admins read on their students' `consent_requests` explicitly "for chasing"): sorted by `expires_at` ascending, orange ≤7 days, red expired; the school admin's action is *nudge/remind* (a request to the system to re-notify the guardian), never sign — signer-only is absolute (§3.4).
- **Billing** — school-settled obligations, consolidated invoices, `invoice_aging` (§3.7, §5). *Working assumption:* read + chase, no settlement action in-product; if schools settle in-product this becomes a payment-link flow, but nothing in the doc grants it, so it isn't drawn (A1).

## B5. Academy staff (Ops / Finance / Audit / Super) — the CRM/ERP shell

Desktop-first, left-rail nav grouped into **regions that render per capability** (A1 again — a finance-only officer's rail simply has no People region; nothing greyed):

| Region | Contents | Capability |
|---|---|---|
| **Queues** | Approvals (registrations, guardian links §3.9) · Consent Ops · Team Formation · Withdrawals | operations |
| **Records** | Students · Guardians · **Enrolments** · Teams · Schools · Programmes | operations |
| **Delivery** | Sessions · Assessments | operations |
| **Finance** | To Record · To Confirm · Orders & Receipts · Refunds & Credit Notes · Invoice Aging · Reconciliation | finance |
| **Audit** | Audit Explorer · Consent Evidence | audit_read |
| **Config** | Programme wizard · Fee items · Consent templates · Withdrawal policy · Capacity | per config capability |

Notes with citations:

- **Enrolments as a first-class record object.** The enrolment is the pivot of the whole model (§2) — exactly EDA's Program Enrollment. Staff need to search/filter enrolments directly (by programme × stage × school), not only reach them through students. This is the single biggest divergence from a generic "Contacts CRM" and the doc's §8 framing endorses it.
- **Team Formation queue = read-only occupancy board** (A4): columns *In pool → Forming → Submitted → Confirmed* showing entitled counts and cards; card → side panel; panel buttons request transitions. The **Confirm Team** consequence sheet previews the server's invariant checks *as a checklist* — consent satisfied per member ✓/✗, minimum size ✓/✗ (§3.3) — so ops sees *why* a confirm will fail before requesting it. Failing rows link to the blocking record (e.g. the unsigned consent request).
- **The activity-timeline trap.** Every CRM reference (Lightning activity timeline, Odoo chatter, Dynamics timeline) puts a history feed on the record page. Here the history source is `audit_events`, readable by **audit_read only** (§4). So: the timeline card **exists only for capability-holders**; ops without audit_read gets a record page with *no history card at all* — state is shown as *current* state plus the domain's own dated fields (`verified_at`, `signed`, receipt dates), which are entitled reads. Do not synthesize a pseudo-timeline for ops from audit data the DB would refuse them.
- **Audit persona** — read-everything, act-on-nothing: record pages render with zero action buttons (write affordances are entitlement-shaped too), plus the Audit Explorer: filter by entity/actor/action/programme over the append-only log (§3.9), rendered as an immutable ledger — no edit affordances exist to remove.
- **Super** composes capabilities; the shell is the union of entitled regions. Capability *composition* also resolves the finance name question — see E2.

---

# Part C — The record page pattern

The record page is where the CRM references matter most — and where every one of them needs the entitlement re-read. The shared anatomy, then the four compositions that matter.

## C1. Anatomy (staff grammar)

Modeled on the Lightning record page, restated under the iff rule:

1. **Header band** — entity type eyebrow (muted), name (Primary text), primary state as dot+word chip, key identifiers. Actions cluster right: **every button is a transition request** (A4); at most one gold.
2. **Highlights strip** — 3–5 *decision-evidence* facts pinned above the fold (Salesforce "highlights panel"). This is where §6 fields live on staff pages: stage, `expires_at`, `payment_due_at`, next `starts_at`. Highlights are chosen per entity type in D below.
3. **Body: main column + rail.** Main = the **spine** (the entity's children collections — related lists). Rail = **association cards** and **ceremony cards**. On Surface 1; inner tiles Surface 2 (Color §2).
4. **History card** — audit timeline, **iff audit_read** (B5).

Card taxonomy (used everywhere below):

- **Collection card** — a related list (enrolments on a student; teams on a programme). Row count shown *is* the entitled count. Rows link iff A3.
- **Association card** — a *link-row projection*: guardian / school / mentor / team membership. **Exists iff the link row is returned; display-only when the viewer has no drill target** (§5 "linkified names"). An association card with no drill renders name-as-text + relationship metadata + status chip, and visually reads as *information*, not as a disabled navigation item — no chevron, no hover affordance.
- **Ceremony card** — a state-machine object addressed to someone (consent request, order): current state, its urgency field rendered as a countdown chip, and the single entitled action if the viewer is the actor.
- **Embargoed card** — results (E1).

## C2. Composition 1 — the child record page (guardian view; family grammar)

Single column, stacked:

1. **Identity header** — child's name, school chip (iff active `school_link`; display-only: the guardian has no school record to open, A3).
2. **Enrolment cards** — the spine (§2). Each: programme banner/brand-color band, mini journey stepper (gold current step), one urgency chip max, team name if teamed. Tap → the scoped programme space (B2).
3. **Consent card(s)** — ceremony cards, one per outstanding request, countdown chips, gold **Sign** when signable.
4. **Fees card** — outstanding orders summarized; drill to Payments (B2).
5. **Guardians card** — the guardian themself, with `origin` + `verified_at` shown as trust evidence (§3.1 — surfacing the child-safety backbone builds warranted confidence). *Working assumption:* a co-guardian of the **same child** appears here name-only; if `users` reads don't return co-guardians, the card shows only self and the design loses nothing (A1 makes the ambiguity safe: the card renders what returns).
6. **Withdrawal card** — iff a `withdrawal_request` exists (§3.8): state, refund-window band, endorsements.

The student's own view of themself is the same page minus Fees, minus signing authority (Consent renders as observed status + "remind my guardian"), per §5's student column.

## C3. Composition 2 — the Student 360 (ops view; staff grammar)

- **Header:** Student · name · overall standing chip · IDs.
- **Highlights:** active enrolments count · school · guardian-link status (`verified_at`) · next session across enrolments.
- **Main (spine):** the **Enrolments collection card** — one row per enrolment: programme, stage stepper (compact), team chip, consent chip, order chip. **There are deliberately no global Team/Sessions/Results cards on the student page** (§2, §7.3) — those live on the *enrolment* record (C4). The student page answers "who is this person and what are they in"; the enrolment page answers everything else. This is EDA's Contact→Program Enrollment split exactly (§8).
- **Rail (associations):** **Guardians** (linked rows with `origin`, `verified_at`, status — the child-safety evidence, §3.1; names link, ops is entitled) · **School** (active link; name links) · **Tenures** (§3.5, participation history).
- **Actions:** ops-actored transitions only — e.g. manual consent issuance (§4 consent_requests writes). Enrolment creation is *not* here for ops-as-ops: enrolment insert is guardian-or-system (§4) — batch enrolment flows through `enrolment_batches` (§3.3) as its own queue, not a button on the person.
- **History:** iff audit_read (B5).

School-admin's student page is this composition filtered through their column of §5: enrolments/team/sessions/**released** results; guardian card **absent** (nothing in §4 grants school admins guardian-link reads — the card doesn't render, A1; the consent *chase* queue B4 gives them what they operationally need without exposing the guardian relationship object).

## C4. Composition 3 — the Enrolment record page (staff; the pivot)

The page that makes the whole model legible. Header: **student × programme**, stage as a full-width journey stepper (gold current step). Highlights: stage · consent state + `expires_at` · order state + `payment_due_at` · team.

Main column: **Consent** ceremony card (request state; signature evidence — version, sha-256, language, signed-at — entitled to ops/audit, §4 `consent_signatures`) · **Order & payments** (lines, receipts w/ sequence, obligations family-vs-school, §3.7) · **Team membership** (team, role, lobby) · **Session bookings** · **Results** (all states with staff-only banners, E1) · **Withdrawal** iff exists.

Rail: Student (links), Programme (links), Acting guardian (`acting_guardian_id`, §3.3 — links for ops), Mentor (via team, names link for ops).

Family personas never see this page as such — their enrolment view *is* the scoped programme space (B1/B2), same data, family grammar.

## C5. Composition 4 — the Programme record page (staff)

Header: programme name, publication status, enrolment-window chip. **Banner card** shows the real `uploads` pipeline state (§3.9): the clean image, or "pending scan" (pending-blue), or quarantined (danger + `scan_signature`), with brand-color fallback preview — designing the imagery slot against the actual field (§3.2). Main: **cohort funnel** (occupancy counts across the enrolment lifecycle — legitimate for ops, whose reads return everything, §4) · Teams collection · Sessions · Assessments (with release states, E1) · Fee items · Capacity · Wizard sections · Versions. The `mentor_team_access` flag is shown *on the programme page* as a governance fact with its consequence spelled out ("mentors on this programme can see their team's roster, names-only").

---

# Part D — Surfacing the decision evidence (§6)

The doc's own finding: mechanics correct, decision evidence off-screen. Placement rule: **each §6 field appears (a) at the point of decision, (b) in the relevant queue's sort order, and (c) on the record's highlights** — three placements, one source of truth.

| Field | Family placement | Staff placement | Color |
|---|---|---|---|
| `consent_requests.expires_at` | Guardian **Home inbox** item + Consents queue countdown + the signing screen itself ("expires in 6 days") | School-admin chase queue **sort key** (B4); Consent Ops queue; enrolment highlights | pending blue → warn orange ≤7d → danger red expired (A5). Never gold (Color §6) |
| `orders.payment_due_at` + lines | Guardian Home inbox + Payments tab; **line items itemized on the pay screen** — what's owed, for what, by when (§3.7) | Finance aging views; enrolment highlights | orange due-soon / red overdue / green settled |
| Enrolment stage | The **journey stepper** on every enrolment card (mini) and scoped space (full) — "where is my child in the journey" answered at a glance | Full stepper on enrolment page header; funnel on programme page; Team Formation board columns | Gold = current step only; done = green check; future = muted (A5) |
| Assessment `released` | The family Results tab **changes shape at release** (E1) — and release is the notification moment (push/inbox: "Results for X are out") | Release action + irreversible consequence modal; post-release banner with released-at + actor | staff-only pre-release states carry warn-orange "not visible to families" |
| `sessions.starts_at` + booking status | "Next up" strip on both family Homes; scoped Sessions tab | Mentor **Today** view (B3); ops session board; enrolment bookings card | pending blue upcoming; danger cancelled |
| Withdrawal refund-window | **Leads the withdrawal consequence sheet** — "A full refund is available until 24 Aug" shown *before* the guardian requests (§6, A4); then on the withdrawal card | Withdrawals queue shows each request's band — ops decisions are band-aware | green full-window → orange partial → red closed |

The pattern across all six: urgency is **real data rendered as a chip with a deadline**, never decorative, and never gold (Color §1 — gold would make urgency read as brand).

---

# Part E — The hard edges

## E1. The assessment embargo (§3.8, §7.5)

The RLS splits two grains: the family may read that an assessment **exists** (title/status, roster rule) but a **result row returns only when the assessment is `released`** (§4). The UI must add one coarsening on top: the raw status vocabulary includes `graded`, and showing a family "graded" *is* a glimpsed pending score — the thing §7.5 forbids in spirit even though the read is technically permitted. So:

- **Family Results tab, pre-release:** assessments listed with **coarsened** status copy — everything from `published` through `graded` renders as *"In progress — results not yet released"* (pending blue). No per-student rows exist (the DB returns none — the empty result set *is* the design, A1).
- **At `released`:** the tab re-composes — released assessments become result cards (score, released date), and the release event is the notification trigger (D). Unreleased ones stay coarse.
- **Staff:** all states visible with the persistent warn-orange *"staff only until released"* banner on `graded` (A5). **Release** is the flagship irreversible action (A4): full consequence modal — *"Releasing 〈assessment〉 makes N results immediately visible to N families. This cannot be undone."* — with reason capture. Graders grade; **release lives with academy ops**, separating authorship from publication.
- School admins see **released only** (§5) — their student pages simply gain result rows at release, same coarsening before.

## E2. Finance: four-eyes + name-blanking (§3.7, §7.6)

Two rules, two patterns:

**Name-blanking.** A finance-only capability is *not entitled to the child's name* (§3.7). Finance surfaces therefore identify rows by **order/receipt reference + programme + school + obligation type**, with the person column rendering "—" — a designed mask, not a broken lookup: same muted style everywhere, so its meaning becomes learned. Never substitute a pseudo-identifier derived from the name. **Capability composition resolves it naturally:** an officer holding finance *and* operations has entitled user reads, so names render (linked, A3) for them — the page shape follows the reads, per viewer, automatically (A1). Don't special-case "the finance screen"; there is no finance screen, only finance *reads*.

**Four-eyes.** Two queues, not one: **To Record** and **To Confirm**. A payment recorded by officer F1 appears in To Confirm *for other finance officers*; for F1 the row shows confirm **disabled-with-reason** — *"You recorded this payment; a second officer must confirm"* — the one sanctioned disabled state (A4, §3.7's own prescription: the row is an entitled read, the write is role-split). Confirm itself is a consequence-sheet transition (it mints the receipt against the monotonic sequence, §3.7). The reconciliation surface (`reconciliation_log`, §3.9) renders run results as pass/fail assertion lists — green/red status vocabulary, and a red assertion links to the offending rows *within finance's entitled reads*.

## E3. Cross-family isolation (§3.1, §7.7)

Made structural rather than enforced-per-screen: family personas have **no search, no directory, no free-text person lookup anywhere** (§5). The children switcher enumerates `app.student_ids`; adding a person happens only through the sanctioned link ceremonies — invitation, pairing code, parent-initiated, school-mediated (§3.1 `origin` values) — each a guided flow ending in a server-approved link, surfaced afterwards in the Guardians card with its origin shown. Deep-link hygiene per A1: foreign IDs 404. Notifications never carry another family's identifying content. The only place two families' children co-occur on a family screen is the **team roster — names-only by design** (§5), which is the deliberate, minimal cross-family surface the model permits.

## E4. Mentor names-only (§7.8, §3.2)

Three nested gates, each rendered as absence not denial (A1): (1) no `team_teacher_link` → no team card; (2) link but programme flag off → team card without Roster/Tracker tabs; (3) flag on → roster of **plain-text name rows + role chip** — no avatars-as-links, no contact fields, no drill (the mentor has no student record to open, A3), tracker read-only. The mentor's grading capacity (B3) is a separate assignment and never widens roster visibility. Programme page states the flag's consequence in governance language (C5) so ops setting it understands exactly what it opens.

---

# Appendix — pattern-to-reference map

| Pattern here | External reference | The re-read applied |
|---|---|---|
| Student 360 → enrolment cards → enrolment record | Salesforce EDA: Contact → Program Enrollments → Course Connections (§8) | Related lists render entitled rows only; page shape differs per viewer (A1) |
| Highlights strip / record header | Lightning record page highlights panel | Highlights = §6 decision evidence, chosen per entity |
| Association cards | HubSpot record associations; Dynamics "Related" | Exists iff link row; display-only without drill target (§5) |
| Statusbar lifecycle on records | Odoo statusbar (clickable) | **Read-only stepper** + separate transition-request buttons (A4, §7.2) |
| Team Formation board | Any CRM kanban | Read-only occupancy; side-panel actions; no drag (§1 rule 2) |
| Activity timeline | Lightning timeline / Odoo chatter | Renders iff audit_read; ops gets domain-dated facts instead (§4 audit_events) |

*End of proposal. Every card in this document renders iff the viewer's read returns it; every action requests a server transition and shows its consequence first.*

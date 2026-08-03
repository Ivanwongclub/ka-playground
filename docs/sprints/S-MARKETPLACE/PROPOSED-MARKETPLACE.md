# PROPOSED — S-MARKETPLACE (public programme listing / enrolment funnel top)

> **THINK-FIRST scoping pass. Plan only — no code, no commit.** Sourced from the real repo: the old MVP
> under `build-reference/` (read-only reference) and the built KAP engines. The goal is to separate
> **genuinely-new work** from **wiring over machinery KAP already has**, and to flag the interlocks a
> public anonymous surface raises. STEP 4 of S-UX3-3a is unaffected; this is a parallel planning pass.

---

## 1. What the MVP "Marketplace" ACTUALLY is (read from source, not the description)

Read: `build-reference/src/routes/index.tsx`, `p.$id.tsx`, `_authenticated/playground.index.tsx`,
`components/shared/EnrolmentDialog.tsx`, `supabase/migrations/…programmes…sql`.

**The framing ("a public landing page listing current & past programmes") is only half-true in the code.**
The MVP actually had **two** distinct surfaces, and the *public* one is not a list:

- **`/` (index.tsx)** — NOT a marketplace. It is a pure redirect: `session ? /dashboard : /login`. There is
  **no public multi-programme landing page** in the MVP.
- **`/p/$id` (p.$id.tsx)** — a **public, unauthenticated, single-programme preview/share page**. Sticky
  banner: *"Public preview — anyone with this link can see this page."* Share dialog (copy link;
  WhatsApp/Email/X/LinkedIn are "coming soon" stubs). It renders: hero image, category badge, title,
  tagline, chips (age range, duration, period, provider), a **stats strip** (`enrolled_count/capacity`,
  duration, "Certificate on completion", spots-left / "Waitlist open"), a 3-image gallery, and a CTA.
  - **The "Enrol now" CTA is a `<Link to="/login">`.** It does **not** enrol and does **not** register —
    it routes an anonymous visitor to sign-in. Copy: *"secure your child's place today. Sign in… or
    contact our team"* / when full *"sign in to join the waitlist."*
  - Data read: `supabase.from("programmes").select(…).eq("id", id)` — a direct client read with **no
    status filter**.
- **`/_authenticated/playground/` (playground.index.tsx)** — the actual **browse/list grid**, but it is
  **behind login**. CMS hero + announcements + a **featured programme** + category filters + a
  `ProgrammeCard` grid (`supabase.from("programmes").select("*")`). The real enrol path is
  `EnrolmentDialog.tsx` (lists programmes `.neq("status","Closed")`, picks a student, writes an
  enrolment) — again **authenticated**.
- **MVP `programmes` RLS** (migration `…102825…sql`): `create policy "read programmes" … for select
  using (true)`. **Every programme row is readable by anyone (anon included), regardless of status.** The
  status enum is a *lifecycle* — `('Open','Registering','Coming Soon','Closed')` — **not a publish gate.**

**Honest takeaway:** the MVP's *public* marketplace was **per-programme shareable preview pages**, not a
public catalogue; its browse-list was authenticated; and its public read had **no publish gate** (it
leaked any row via `using(true)`). A KAP "public programme catalogue landing page" would therefore be
**more public than the MVP ever was** — a genuine scope expansion, not a like-for-like port.

---

## 2. Map against what KAP has already built (new vs wire-in)

| Capability | KAP today | Verdict |
|---|---|---|
| **Programme publish gating** | `draft → published` one-way (WizardService); post-publish edits re-validated (dates, capacity, locked sections); `PublishedProgrammeCompletenessAssertion` (every published programme has a consent template + ≥1 fee item) and `consent.language_completeness` (all 3 languages present). Version snapshots on publish. | **Built.** A public read must ride this — list only `status='published'` **and** complete. |
| **Public / anonymous READ pattern** | `GET /register/schools` — an **existing anonymous read** (unauthenticated, `throttle:registration`, returns opt-in *listed* schools + a form nonce). | **Built precedent.** The marketplace read is the programme analogue — model it on this exact pattern. |
| **Anonymous WRITE pattern** | S04C — the first anonymous write (`POST /register`, constant-shape 202, no status endpoint). | **Built.** The enrol CTA terminates here (register), not in a new write. |
| **Enrolment engine** | Enrolment-as-intent + consent gate + awaiting-a-team pool (`POST /my/enrolments`, `role:guardian`). | **Built.** "Click to enrol" wires into this — no new enrol engine. |
| **Model B self-register → approve → account** | `/register` → admin approval creates the account (OD-23); email verify (OD-29); account state derives from links (OD-28). | **Built.** The anonymous CTA routes through this funnel. |
| **Presentation / marketing data** | `programmes` columns are governance only: `code, jurisdiction, name_en/tc/sc, payer_party, status`. Dates live in wizard JSON (`basics.enrolment_closes_on/starts_on`, `team_rules.formation_deadline_on`). **No** tagline, category, age-range, duration, brand colour, hero/gallery images, "featured". | **NEW.** KAP has none of the MVP's marketing fields. This is real config/schema work, not a surface. |
| **Public multi-programme catalogue** | None. Every programme read is admin-gated (`configuration.manage`). | **NEW** (and more public than the MVP). |

---

## 3. Anonymous-surface classification

A public programme catalogue is viewable **without login** — the same trust boundary as `/pay/{token}`,
`/register`, `/register/schools`. **Programmes are not minors**, so the child-safety risk is lower than the
minor-facing surfaces — but the anonymous-surface rules still apply:

- **Published-only, complete-only.** The read returns **`status='published'`** rows that pass
  `PublishedProgrammeCompletenessAssertion` (consent template + fee item) and language completeness.
  **No draft, no stale, no incomplete** ever appears. Explicit `WHERE status='published'` in a dedicated
  read — **never** the MVP's `using(true)`, and never reuse the admin RLS path.
- **No PII.** Programme presentation fields carry no personal data; the read must **not** join enrolments,
  guardians, students, or `enrolled_count` in a way that exposes individuals. (See §5 on the capacity bar.)
- **Constant-shape / no enumeration.** A programme id that is not published-and-complete returns the
  **same** "not found" shape as a non-existent id (like `/pay` and `/register`), so the surface can't be
  used to enumerate draft ids or infer state. Throttled (`throttle:*`) like `/register/schools`.
- **Trilingual.** Unlike the EN-only MVP, the listing/detail must be EN + 繁中 + 简中 (OD-19); any new
  marketing copy needs all three languages.
- **Read-only.** No write on this surface; the only action is the CTA hand-off to `/register` or sign-in.

---

## 4. The real deltas (new work vs surface-over-machinery)

1. **Public programmes read endpoint** — `GET /programmes` (public) or `/catalogue`. **NEW**, but small:
   the **anonymous READ analogue of S04C's anonymous WRITE**, modelled on `GET /register/schools`.
   Unauthenticated, throttled, constant-shape; returns **only published+complete** programmes with
   presentation fields; a detail variant `GET /programmes/{id}` (public) for the `/p/:id` analogue.
   *Surface over built publish gating — the delta is the dedicated published-only query + the anonymous
   route, not a new engine.*
2. **Presentation / marketing data** — **GENUINELY NEW** (flag). KAP programmes lack tagline, category,
   age range, duration, hero/gallery imagery, brand colour, "featured". Options: **(a)** a new
   `marketing`/`listing` wizard section (programme config, trilingual, editable pre-publish, part of the
   completeness gate), or **(b)** a minimal derivation (name + dates + capacity) with no imagery. This is
   the one piece that is **not** just a surface over existing machinery.
3. **Public listing UI (landing)** — **NEW** frontend: an anonymous route (like `/register`, `/pay`) with a
   programme-card grid, "current vs past" split, category filter. Design-system themed, trilingual.
4. **Public programme-detail UI** — **NEW** frontend: the `/p/:id` analogue (hero, chips, stats, CTA,
   share). Reuses S-UX2a display kit conventions.
5. **Enrol CTA wiring** — **mostly wire-in.** Anonymous visitor → `/register` (Model B). Logged-in guardian
   → the built enrol flow (`/my/enrolments`). No new write. *(See §5 — must not imply instant enrolment.)*

**Not-just-a-surface flags:** delta #2 (marketing data) is real new config/schema; delta #1 needs its own
child-safety-adjacent review (a new anonymous read); #3/#4/#5 are surfaces/wiring.

---

## 5. Interlocks & conflicts (the point of think-first)

- **Publish/RLS conflict — none if done right.** KAP has *no* public programmes read today; all are
  `configuration.manage`-gated. The new endpoint must be a **dedicated public read with an explicit
  `status='published'` filter**, NOT a relaxation of the admin gate or a `using(true)`-style blanket. The
  MVP's blanket read is the anti-pattern; avoid it. Ties directly to `PublishedProgrammeCompletenessAssertion`.
- **"Past programmes" — derivable, no new state needed.** KAP already stores `basics.enrolment_closes_on`
  and `basics.starts_on` (and `deadline.ordering` enforces their order). "Current" = published &
  enrolment window open / upcoming; "Past" = published & `starts_on`/end in the past. Derive from the
  timeline — **do not add a new lifecycle state.** (If a hard "archived" hide is later wanted, that's a
  separate publish-state decision, not required for v1.)
- **Capacity display conflict — real, must be handled.** The MVP showed a live `enrolled_count/capacity`
  progress bar. **KAP does not claim seats at enrolment** — enrolment is *intent* (OD-31/34); seats are
  claimed **per team at 成團** against `programme_capacity(capacity, claimed)`. So a public "X spots left"
  per programme is **not** the MVP's meaning and risks implying per-person seats. **Recommend:** either
  omit the live capacity bar for v1, or derive strictly from `programme_capacity.claimed` with copy that
  matches the team-based model — and **never** expose per-enrolment counts (that edges toward PII/interest
  signals). Flag for the card.
- **Enrol CTA must route through Model B — not instant enrolment.** For an **anonymous** visitor the CTA
  routes to **`/register`** (self-register → admin approval → account → then enrol), never a direct
  enrolment write. (The MVP already routed to `/login`, so this aligns — KAP just points it at `/register`
  for new users, sign-in for existing.) The card copy must not promise "enrolled" from one click; it
  promises *"start your enrolment"*.
- **Trilingual + design system.** Public pages must be EN/繁/简 and darkAlgorithm/Design-System v2.1
  compliant. Any marketing copy is trilingual and, if placeholder, flagged non-binding (like consent
  placeholder text).
- **No co-branding.** The MVP page showed a "provider_short / Kings Armour" chip and "Certificate on
  completion". KAP: certificates are **academy-issued only, no co-branding** (Spec 1842 struck). The
  public detail must not resurrect partner/provider branding or third-party certification claims.

---

## 6. Recommendation — one card or a split, and where in the queue

**Split into two cards** (the anonymous read is review-critical and should land before the UI):

- **S-MARKETPLACE-A — the public catalogue read + presentation-data decision (backend, review-critical).**
  The dedicated **anonymous, published-only** programmes read (`GET /programmes` + `/programmes/{id}`,
  throttled, constant-shape), its **own** child-safety-adjacent authority/leak test (published-only, no
  draft/stale, constant-shape no-enumeration, no-PII), a reconciliation tie-in (the public read never
  returns a row that fails `PublishedProgrammeCompletenessAssertion`), **and the presentation-data
  ruling** (new `marketing` wizard section vs minimal derivation — decide before building the UI). Reviewed
  at S-FIX / anonymous-surface level, like S04C.
- **S-MARKETPLACE-B — the public landing + programme-detail UI + enrol CTA wiring (frontend).** The
  anonymous catalogue grid (current/past split), the `/p/:id` detail analogue, the CTA into Model B /
  sign-in. S-UX2a display kit + trilingual + design system. Risk shots: published-only listing, a
  draft/stale correctly absent, the anonymous CTA landing on `/register`.

**Queue position:** **independent of the remaining S-UX3 chunks** (roles STEP 4, S-UX3-3b, S-UX3-4/5/6/7)
— it touches programmes + onboarding, not teams. It is the **top of the enrolment funnel** and the highest
client-visibility surface (the public "front door"), so it has a strong claim to priority — but it is a
**net-new public surface with new marketing data**, heavier than the S-UX3 wire-ins. Recommend slotting it
**after S-UX3-3a STEP 4 closes** (finish the in-flight card first), as its own **S-MARKETPLACE (A→B)**, with
A gated on the presentation-data decision. If the client wants the public front door sooner, A can start in
parallel with S-UX3 STEP 4 since there is no code overlap.

---

## Open decisions this pass raises (for Leo)
- **OD-?: presentation data** — add a trilingual `marketing` programme-config section (title/tagline/
  category/age/duration/imagery), or launch v1 with name + dates + no imagery? (Blocks S-MARKETPLACE-A's
  data contract.)
- **OD-?: public capacity display** — omit the live capacity bar in v1, or derive from
  `programme_capacity.claimed` (team-based, not per-enrolment)? (Child-safety-adjacent: no per-person signal.)
- **OD-?: "past programmes" visibility** — derive from the timeline (recommended) or introduce an
  `archived` publish state?
- **OD-?: is the public catalogue a v1 goal at all**, or are per-programme shareable `/p/:id` pages (the
  MVP's actual public surface) sufficient for the first cut?

*No code, no schema, no endpoint created in this pass — plan only. Awaiting review.*

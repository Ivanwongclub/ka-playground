# KA Playground — Full Platform Specification (v4)

**Version:** 4.2 — supersedes v1, v2, v3, v4.0/4.1
**Date:** 23 July 2026
**Prepared by:** Tune Bright Limited
**Client:** Kings Armour / Armour Academy

**New in v4.2:** All 16 completeness-review amendments folded into their home sections — withdrawal policy (E7), guardian continuity (B8), auth lifecycle & Google sign-in (B9–B10), session/mentor/assessment lifecycles (C4), concurrency & idempotency (E8), upload hardening & production ops (O2), schema additions (N), new assertions (P3)

**New in v4:** Roles & relationship model · Programme setup wizard · Enrolment–Consent–Payment close-loop · Order & receipt module · In-house e-signature · Bulk enrolment · Role-based dashboards · Reporting & AR/AP · Notification engine · Ant Design theming · Data flow, relationship and workflow maps · Schema summary

**Deferred to Phase 2:** Logto identity federation · QFPay gateway integration · external organiser sync

**Positioning confirmed (v4.1):** KA Playground is the digital platform for the **Global Elite Summer Program**, run by **Kings Network** under **King Armour Family Office**, an affiliate of Sunwah Kingsway Capital Holdings Limited (est. 1957). The audience is the **second generation of ultra-high-net-worth client families**, not the general public. This raises the privacy posture materially and changes several defaults — see Part L.

---

# PART A — GOVERNING PRINCIPLES

## A1. Configuration, not code
Programmes are hosted by different external organisers and each is unique. Nothing programme-specific may be hard-coded. The platform is an **engine plus a configuration surface**. The Admin portal is not a thin CRUD layer — it is the product. Roughly 40% of Phase 1 effort belongs there.

## A2. Fixed vs configurable

| Fixed platform-wide | Configurable per programme |
|---|---|
| Five Activity Tracker stages: Plan · Design · Learn · Pitch · Launch | What each stage requires — milestones, deliverables, gate conditions, approver |
| Workflow state machines | Team size min/max, categories, approver, deadlines, visibility |
| Audit and reconciliation model | Role names, cardinality, rotation cadence |
| Five user roles | Deliverable types, formats, review flow, rubric |
| Learn as attendance-driven | Attendance threshold |
| Finance as record-only | Budget categories, approval chain |
| Consent-before-enrolment gate | Consent template content, required signers |
| Sequential receipt numbering | Fee items, instalments, discounts, payer party |

## A3. Finance is record-only (team money)
Team money is handled offline by students or the school. The platform records, routes for approval, and reports. It never holds or moves team funds.

**Separate and distinct:** programme fees paid at enrolment are real money handled by the platform's Order module. The two never mix.

## A4. Every state change is an event
No entity sits in an undefined state. Every transition writes to an append-only audit log. Reports read from source of truth, never from cached counters.

---

# PART B — ROLES & RELATIONSHIPS

## B1. The five roles

| Role | Definition | Created by |
|---|---|---|
| **Student** | The learner. Enrols, joins teams, progresses through the Activity Tracker. | Self-registration or bulk import |
| **Parent / Guardian** | Legally responsible adult. Signs consent, is default payer. | Self-registration or invited by student |
| **Teacher** | Delivers and supervises. Approves team formation, budgets, deliverables. | Invited by School Admin or Academy Admin |
| **School Administrator** | Manages a partner school's teachers, students, enrolments, billing. | Invited by Academy Admin |
| **Academy Administrator** | Platform owner. Full configuration and oversight. | Seeded / invited by another Academy Admin |

A single person may hold more than one role via separate accounts (a teacher who is also a parent). Roles are not stacked on one account — this keeps consent and audit attribution unambiguous.

## B2. Relationship types

| Relationship | Cardinality | Required for | Verification |
|---|---|---|---|
| Student ↔ Parent | Many-to-many | **Enrolment (mandatory)** | Pairing code or email invite |
| Student ↔ School | Many-to-one active | School-sponsored enrolment, consolidated billing | School-mediated |
| Student ↔ Teacher | Many-to-many | Progress supervision, approvals | Teacher- or school-initiated |
| Teacher ↔ School | Many-to-one | Teacher scope | School Admin assigns |
| Student ↔ Team | One active per programme | Activity Tracker stages 1, 2, 4, 5 | Team formation flow |
| Team ↔ Programme | Many-to-one | Everything | Set at team creation |

## B3. Relationship map

```
                        ┌──────────────────┐
                        │ ACADEMY ADMIN    │
                        │ (platform owner) │
                        └────────┬─────────┘
                                 │ invites / configures
                 ┌───────────────┼────────────────┐
                 v               v                v
        ┌────────────────┐  ┌─────────┐  ┌──────────────┐
        │ SCHOOL ADMIN   │  │PROGRAMME│  │   TEACHER    │
        └───────┬────────┘  └────┬────┘  └──────┬───────┘
                │ employs        │              │
                │ ┌──────────────┘              │
                v v                             │
        ┌──────────────┐                        │
        │   TEACHER    │                        │
        └──────┬───────┘                        │
               │ supervises                     │ supervises
               v                                v
        ┌─────────────────────────────────────────────┐
        │                 STUDENT                     │
        │  - one active team per programme            │
        │  - must have >=1 active guardian link       │
        └───────┬──────────────────────┬──────────────┘
                │ guardian link        │ membership
                │ (MANDATORY)          │
                v                      v
        ┌──────────────┐        ┌─────────────┐
        │   PARENT     │        │    TEAM     │
        │  - signs     │        │ - roles     │
        │    consent   │        │ - rotation  │
        │  - default   │        │ - ledger    │
        │    payer     │        └──────┬──────┘
        └──────────────┘               │ belongs to
                                       v
                                ┌─────────────┐
                                │  PROGRAMME  │
                                └─────────────┘
```

## B4. Linking mechanism — pairing code (built in-house)

**Student-initiated (default):**
1. Student opens Profile › Connections › Invite Guardian
2. System generates a 6-character alphanumeric code, case-sensitive
3. Code expires after 7 days or first successful use, whichever comes first
4. Maximum 5 active codes per student at any time
5. Student gives the code to the parent by any means
6. Parent registers or logs in, enters the code under My Children › Link a Child
7. System matches, creates the link in `pending` state
8. Student confirms the request (prevents a mistyped code linking a stranger)
9. Link becomes `active`

**Parent-initiated (alternative):**
Parent enters the student's registered email → system emails the student → student approves → link active.

**School-mediated (bulk):**
School Admin uploads a roster containing parent email addresses → system emails each parent an invitation carrying a signed, single-use token → parent registers → link auto-activates because the school vouched for it.

## B5. Relationship state machine

```
 Requested ──> Pending Confirmation ──> Active ──> Revoked
     │                  │                  │
     │                  └──> Expired       └──> Superseded
     └──> Cancelled          (7 days)           (guardian change)
```

Every transition is audit-logged with actor, timestamp and reason. Revocation never deletes history — a revoked guardian link remains visible in the audit trail because it may have signed a consent form that is still legally in force.

## B6. Multiple and changing guardians
- A student may have several active guardian links (both parents, or a parent and a legal guardian)
- Each guardian has independent portal access and sees the same child data
- **Only one guardian needs to sign a given consent form** unless the programme config sets `consent_requires_all_guardians = true`
- Separated guardians are independent accounts; neither sees the other's contact details
- A guardian change revokes the old link and creates a new one; consents already signed remain valid against their signed version

## B7. Permission model
Two layers, as established in earlier versions:
1. **Role defaults** — what any holder of a role can do, defined in the Role & Permission matrix
2. **Per-link overrides** — a JSONB column on the relationship record narrowing or widening access for that specific pairing

Effective permission = role default, then link override applied, then programme scope applied. The Admin portal exposes an "Effective Permissions Preview" so an administrator can see exactly what a given user can do on a given student before saving.

## B8. Guardian continuity — the sole-guardian rule

Revoking or deleting a guardian link is unrestricted **except** when it is the student's last active link and the student holds any enrolment in Consent Pending, Payment Pending or Active. In that case:

1. The revocation cannot be executed by the guardian or student alone — it requires **Academy Admin action** with a recorded reason
2. Executing it opens a **guardian replacement exception** with a 14-day deadline, visible in Admin › Exceptions and to the School Admin
3. If unresolved at the deadline, affected enrolments move to **Suspended** (not Withdrawn — no financial consequence is triggered automatically)
4. Consents already signed remain valid: a signature binds to its template version and signer identity at signing time, and revoking the link later does not unsign it

This closes the loop found in review: a student can never silently end up actively enrolled with no consent-capable guardian.

## B9. Authentication lifecycle

Phase 1 authentication is Laravel Sanctum. The full lifecycle, all events audit-logged:

| Flow | Behaviour |
|---|---|
| **Onboarding** | Invitation-only (L4). Academy issues an invitation → recipient opens a single-use tokenised link (14-day expiry) → sets password → **email verification is mandatory before first login completes** → guardian then creates or links the student account |
| **Password reset** | Tokenised email link, 1-hour expiry, single use. Reset invalidates all active sessions. |
| **Lockout** | 5 failed attempts → 15-minute lock. Audit-logged; Academy Admin can unlock early. |
| **Sessions** | 12-hour idle expiry on web; optional remember-me 30 days. Role change or permission edit invalidates the session. |
| **Throttling** | Auth endpoints 5/min/IP · pairing-code attempts 5/hour/account, with the code hard-invalidated after 10 global failed attempts · consent-reminder trigger enforced server-side at 1/24h · API default 60/min/user |

Audit event types added for auth: `login`, `logout`, `failed_login`, `lockout`, `reset_requested`, `reset_completed`, `invitation_accepted`, `email_verified`.

## B10. Registration and Google one-click sign-in

**The direct answer: yes — Logto's native Google connector supports Google One Tap** (the one-click prompt) as well as the standard "Continue with Google" button, configured from the Logto dashboard with no custom code. When Logto arrives in Phase 2, One Tap comes with it.

The phasing and the platform's invitation-only posture shape how it is used:

| | Phase 1 (Sanctum) | Phase 2 (Logto) |
|---|---|---|
| Google sign-in | "Continue with Google" via **Laravel Socialite** — a standard redirect flow, modest effort, optional | **Native Google connector incl. One Tap**, dashboard-configured |
| One Tap specifically | Possible but custom (Google Identity Services JS + server-side ID-token verification) — **not recommended for Phase 1 effort** | Built in |
| Profile sync | n/a | Set to **"sync at sign-up only"** so a later Google profile picture change never overwrites a moderated avatar |

**Three rules that hold in both phases:**

1. **Google sign-in is a sign-in method, not a registration channel.** The platform is invitation-only (L4). A Google identity is accepted only when it arrives through a valid invitation token, or matches the verified email of an existing invited account (account linking). One Tap on the public login page must not create accounts.
2. **It is primarily for guardians and staff.** Standard Google accounts require the holder to be 13+ (varies by country), and younger students may have no Gmail at all. Students authenticate with credentials created during guardian-led onboarding; guardians and staff get the one-click convenience.
3. **Social sign-in never bypasses profile completion or consent.** After a first Google sign-in the progressive completion wizard (role details, student linking, consent capture) still gates programme access.

---

---

# PART C — ACTIVITY TRACKER

Five fixed stages. The Tracker is a **view over functional modules**, not a data store of its own — it owns only stage definitions and gate records.

| Stage | Scope | Content | Gate condition | Data lives in |
|---|---|---|---|---|
| **Plan** | Team | Project management, financial planning, leadership planning | Budget approved **and** mandatory roles assigned **and** project plan submitted | Team Workspace · Finance › Budget · Team › Roles |
| **Design** | Team | Design **and** build, performed on the external organiser platform | External progress threshold reached, or manual sign-off | External organiser (mirrored) |
| **Learn** | **Individual** | Attending Lessons and Events hosted by KA | Attendance threshold met | Learn › Lessons · Learn › Events |
| **Pitch** | Team | Marketing and sponsorship | Funding target or sponsor count reached | Team › Deliverables · Finance › Fundraising |
| **Launch** | Team | The culminating event | Designated event attended **and** results recorded | Learn › Events · results record |

**Learn is the only individually-scoped stage.** A team therefore holds members at differing Learn completion. Team-level Learn displays as a roll-up ("8 of 12 members have met the threshold"), and the gate passes when a configurable percentage of members individually qualify.

## C1. Stage gate workflow

```
  Not Started
       │  first artefact created
       v
  In Progress ──────────────────────────┐
       │  all gate conditions met       │ condition regressed
       │  AND team submits              │ (e.g. member left, budget reopened)
       v                                │
   Submitted                            │
       │  approver opens                │
       v                                │
  Under Review                          │
       │                                │
       ├── approve ──> Passed ──────────┘
       │                  │
       │                  └──> next stage becomes In Progress
       │
       └── return ──> Returned ──> In Progress
                      (reason mandatory, visible to team)
```

Gate conditions are evaluated **live from the owning modules**, never from a cached flag. A gate cannot show "passed" if its underlying condition has since regressed — the nightly reconciliation job asserts this.

## C2. Activation sequencing

| Student state | Activity Tracker shows |
|---|---|
| Enrolment not yet Active | Enrolment status timeline (consent / payment) — Tracker locked |
| Active, no team | "Join or create a team to begin." Learn stage available. |
| In an unlocked team | Formation progress and members needed. Learn available. |
| Team locked | Full five-stage Tracker |

## C4. Session, mentor and assessment lifecycles

The Learn stage runs on sessions, mentors and assessments. Review found all three lacked lifecycles; they are defined here because their data feeds the Learn gate.

**Session (lesson or event):**

```
  Draft ──> Published ──> Full ──> In Progress ──> Completed
              │            │                          
              ├──> Cancelled (all bookings cancelled, waitlist cleared,
              │              session.cancelled fires on all channels)
              └──> Rescheduled ──> Published (bookings retained)
```

- **Reschedule** is a first-class transition, not cancel-and-recreate: bookings are retained, a `session_versions` row records old/new datetime and reason, `session.rescheduled` notifies every booked student and guardian, and booking re-opens if capacity increased
- Attendance can be taken only in In Progress or Completed
- A Published session whose end time passes without reaching a terminal state is flagged by the nightly assertions — no session is left dangling

**Mentor:** `Active → Inactive → Departed`. Inactive blocks new bookings but keeps existing ones. The transition to Departed is **blocked while future sessions exist** — each must first be reassigned to another mentor or rescheduled/cancelled through the session machine above.

**Assessment:** `Draft → Published → Open → Closed → Graded → Released`. Results become visible to student and guardian only at Released; they aggregate in Profile › Achievements (ownership unchanged from Part C resolutions).

---

---

# PART D — PROGRAMME SETUP WIZARD

An administrator configuring a programme must set roughly 80 interdependent parameters. A single long form guarantees omissions. The pattern is a **hub-and-spoke checklist with a publish-readiness gate**, not a linear stepper — sections are interdependent and admins work non-sequentially.

## D1. The hub

```
┌─────────────────────────────────────────────────────────────┐
│  PROGRAMME: STEM on Car 2026                    [ DRAFT ]   │
│  Readiness: 6 of 9 sections complete                        │
│  ████████████████████░░░░░░░░                    67%        │
├─────────────────────────────────────────────────────────────┤
│  ✓ 1. Basics & Media               complete                 │
│  ✓ 2. Eligibility & Capacity       complete                 │
│  ✓ 3. Fees & Payment               complete                 │
│  ✓ 4. Consent Form                 complete                 │
│  ⚠ 5. Activity Tracker             3 of 5 stages configured │
│  ✓ 6. Team Rules                   complete                 │
│  ✓ 7. Role Library                 complete                 │
│  ○ 8. Learning (Lessons & Events)  not started              │
│  ⚠ 9. Certification & Badges       criteria incomplete      │
│  – 10. Integration                 deferred (Phase 2)       │
├─────────────────────────────────────────────────────────────┤
│  [ Save Draft ]     [ Pre-flight Check ]     [ Publish ]    │
│                                       (disabled until 100%) │
└─────────────────────────────────────────────────────────────┘
```

Each section opens as a spoke, saves independently, and returns to the hub with its status recalculated.

## D2. Section contents and dependencies

| # | Section | Key fields | Depends on | Blocks |
|---|---|---|---|---|
| 1 | **Basics & Media** | Name, code, category, organiser, description, hero image, gallery, start/end dates | — | Everything |
| 2 | **Eligibility & Capacity** | Age range, prerequisites, min/max enrolment, waitlist on/off | 1 | Enrolment |
| 3 | **Fees & Payment** | Fee items, currency, instalment plan, discounts, payer party (parent/student/school), payment deadline, **withdrawal policy** (full-refund-before date, pro-rata bands, no-refund-after date, approval required) | 1 | Order creation, withdrawal handling |
| 4 | **Consent Form** | Template selection or authoring, required signers, all-guardians flag, re-consent policy | 1 | **Enrolment cannot open without this** |
| 5 | **Activity Tracker** | Per stage: milestones, required deliverables, gate conditions, approver role, sequence lock | 6, 7 | Publish |
| 6 | **Team Rules** | Min/max size, categories, approver, visibility default, formation open/close/lock dates, auto-matching | 1 | Section 5 team-scoped stages |
| 7 | **Role Library** | Role names, min/max holders, mandatory flag, in-team permissions, rotation cadence | 6 | Section 5 Plan gate |
| 8 | **Learning** | Lesson series, events, attendance threshold, which sessions count toward Learn | 1 | Section 5 Learn gate |
| 9 | **Certification & Badges** | Completion criteria, attendance threshold, assessment threshold, certificate template, co-branding, badge triggers | 5, 8 | Publish |
| 10 | **Integration** | Organiser, protocol, field mapping, sync schedule | 1 | Design stage live sync (Phase 2) |

## D3. Dependency handling
Sections that depend on incomplete prerequisites are **visible but disabled**, with an inline explanation: *"Configure Team Rules first — Activity Tracker stage gates reference team roles."* This teaches the model rather than hiding it.

Conditional fields appear only when relevant: instalment fields appear only if instalments are enabled; all-guardians flag appears only if the consent template requires a guardian signature.

## D4. Publish-readiness (pre-flight)
Pressing **Pre-flight Check** runs every validation and returns a categorised report:

| Severity | Meaning | Blocks publish |
|---|---|---|
| **Error** | Mandatory configuration missing or contradictory | Yes |
| **Warning** | Legal but probably unintended | No |
| **Info** | Suggestion | No |

Examples of each: *Error* — "Consent template not selected; enrolment cannot open." *Warning* — "Team maximum (6) is below the minimum enrolment (10); some students will not fit into a team." *Info* — "No hero image set; the catalogue card will show a colour block."

## D5. Programme lifecycle states

```
  Draft ──> Ready ──> Published ──> Enrolment Closed ──> Running ──> Completed ──> Archived
    ^         │           │                                              │
    └─────────┘           └──> Unpublished (withdrawn, no enrolments)    └──> Cloned to Template
```

**Published is a one-way door for pricing and consent.** Once one student has enrolled, fee items and the consent template are locked; changes create a **new version** rather than editing in place, so existing enrolments retain the terms they agreed to.

## D6. Templates
Any configured programme can be saved as a template. Creating from a template clones all ten sections and drops back to Draft, so a second cohort or a second organiser is a clone-and-edit exercise rather than a rebuild.

---

# PART E — ENROLMENT, CONSENT & PAYMENT CLOSE-LOOP

## E1. The ordering decision — consent BEFORE payment

**Recommendation: consent is collected before payment, both inside one parent-facing flow.**

| | Consent first | Payment first |
|---|---|---|
| Minor enrolled without guardian authority | Impossible | Possible until consent arrives |
| Refund needed if consent declined | Never | Yes — refund plus gateway fee |
| Audit clarity | Clean: authority precedes money | Muddled |
| Parent friction | One flow, two actions | One flow, two actions |
| Seat held while waiting | Needs a hold window | Seat already paid |

The only real cost of consent-first is that a seat must be held while consent is outstanding. That is solved with a configurable **hold window** (default 7 days) after which the pending enrolment expires and the seat returns to the pool.

## E2. Preconditions

An enrolment cannot be created unless:
1. The student has **at least one active guardian link** (Part B)
2. The programme is **Published** and within its enrolment window
3. The student satisfies eligibility (age, prerequisites)
4. Capacity is available, or the waitlist is enabled

If guardian linking is missing, the student is routed to Profile › Connections and the enrolment attempt is held as an intent, not created.

## E3. End-to-end workflow

```
STUDENT                 SYSTEM                    PARENT              ADMIN
   │                       │                         │                   │
   │ clicks Enrol          │                         │                   │
   ├──────────────────────>│                         │                   │
   │                       │ check guardian link     │                   │
   │                       │ check eligibility       │                   │
   │                       │ check capacity          │                   │
   │  <── blocked if any fails, with reason ──       │                   │
   │                       │                         │                   │
   │                       │ CREATE Enrolment        │                   │
   │                       │   status=ConsentPending │                   │
   │                       │ CREATE Order            │                   │
   │                       │   status=Draft          │                   │
   │                       │ CREATE ConsentRequest   │                   │
   │                       │ START hold window (7d)  │                   │
   │                       │                         │                   │
   │                       │ notify ─────────────────>│                  │
   │                       │                         │ opens consent     │
   │                       │                         │ reads to bottom   │
   │                       │                         │ signs             │
   │                       │  <──────────────────────┤                   │
   │                       │ RECORD signature+audit  │                   │
   │                       │ GENERATE signed PDF     │                   │
   │                       │ Enrolment=PaymentPending│                   │
   │                       │ Order=Issued            │                   │
   │                       │                         │                   │
   │                       │ notify ─────────────────>│                  │
   │                       │                         │ pays online       │
   │                       │  <──────────────────────┤                   │
   │                       │            ── OR ──                         │
   │                       │  <──────────────────────────────────────────┤
   │                       │                    admin records offline pay │
   │                       │                                             │
   │                       │ Order=Paid                                  │
   │                       │ GENERATE Receipt (seq no.)                  │
   │                       │ Enrolment=Active                            │
   │                       │                                             │
   │  <── notify: enrolment confirmed ──> both parties                   │
   │                       │                                             │
   │ Activity Tracker unlocked                                           │
```

## E4. Enrolment state machine

```
                    ┌──> Declined (guardian refused consent)
                    │
  Intent ──> Consent Pending ──> Payment Pending ──> Active ──> Completed
     │            │                    │               │
     │            └──> Expired         └──> Expired    ├──> Withdrawn
     │               (hold window)        (payment      └──> Suspended
     └──> Abandoned                        deadline)
```

Every transition writes an audit event. Expiry is executed by a scheduled job, never left to a user action.

## E5. Where the student sees status

Student portal › Programmes › My Enrolments shows a **status timeline** per enrolment:

```
  ┌────────────────────────────────────────────────────────────────┐
  │  STEM on Car 2026                             CONSENT PENDING  │
  ├────────────────────────────────────────────────────────────────┤
  │   ●───────────────○───────────────○───────────────○            │
  │  Enrolled      Consent         Payment          Active         │
  │  23 Jul        pending         waiting          —              │
  │                                                                │
  │  Waiting for your guardian to sign the consent form.           │
  │  Requested 23 Jul · expires 30 Jul                             │
  │  Guardian notified 23 Jul, reminded 26 Jul                     │
  │                                                                │
  │  [ Remind guardian ]                                           │
  └────────────────────────────────────────────────────────────────┘
```

The student always knows exactly what is blocking and who is holding it. They may trigger one reminder per 24 hours.

## E6. Payer party — where payment lives

The programme config sets `payer_party` to **parent**, **student** or **school**.

| Payer | Who pays | Student portal shows | Parent portal shows |
|---|---|---|---|
| **Parent** (default) | Guardian | Status + receipt download (read-only) | Pay action, methods, full history, receipts |
| **Student** (16+ programmes) | Student | Pay action, history, receipts | Status + receipt (read-only) |
| **School** | School Admin via consolidated invoice | Status only | Status only |

**Both portals always display the payment record.** Only the *action* moves. This answers the open question directly: the record belongs in both places because both parties need it for their own purposes — the student to know their enrolment is clear, the parent because they paid it and need the receipt.

## E7. Withdrawal and refund close-loop

Review finding A1: Enrolment had `Withdrawn` and Order had `Refunded` with nothing connecting them. The connection is the **per-programme withdrawal policy**, configured in wizard section 3:

| Field | Meaning |
|---|---|
| `full_refund_before_date` | Withdrawal before this date refunds 100% |
| `pro_rata_bands` | Ordered bands `[{until_date, refund_pct}]` |
| `no_refund_after_date` | Withdrawal after this date refunds nothing |
| `withdrawal_requires_approval` | Default true |

**Workflow — the only route to `Withdrawn`:**

```
  Student/Guardian requests withdrawal (reason captured)
        │
        v
  Approver (configured role) reviews
        │
        ├── reject ──> enrolment unchanged, reason to requester
        │
        └── approve
              │
              v
        System computes refund from policy + payments to date
        (computation inputs snapshotted into the audit event)
              │
              ├── refund due ──> Refund record opens PRE-FILLED
              │                  ──> Part F6 refund workflow
              │                  ──> credit note on completion
              │                  ──> order -> Refunded / Partially Refunded
              │
              └── no refund due ──> recorded with policy citation
              │
              v
        Enrolment -> Withdrawn · seat returns to pool / waitlist promotes
        Team membership -> Left (triggers Part G below-minimum check)
        Notifications to student, guardian, school admin
```

Nothing about a withdrawal is discretionary arithmetic: the refund amount is computed, snapshotted and auditable, and the team-side and seat-side consequences fire in the same transaction.

## E8. Concurrency and idempotency

Two race conditions closed:

**Last-seat race (B1).** The capacity check and enrolment insert run in one transaction holding `SELECT … FOR UPDATE` on the programme's counter row. The loser of a simultaneous pair receives a clean outcome — a waitlist offer if enabled, otherwise "programme is now full" — never a phantom over-capacity enrolment. This mirrors the optimistic lock already specified for the last team seat.

**Double-submit (B2).** A partial unique index allows one enrolment per (student, programme) where status is non-terminal — a double-clicked Enrol returns the existing record instead of creating a second intent, order and consent request. Offline payment recording carries a client-generated idempotency key with the same behaviour.

---

---

# PART F — ORDER, PAYMENT & RECEIPT MODULE

Built gateway-agnostic. Offline recording works from day one; QFPay drops into the `payment_attempts` layer in Phase 2 without touching orders, receipts or the ledger.

## F1. Entities

| Entity | Purpose | Mutability |
|---|---|---|
| `fee_items` | Fee definitions on a programme (tuition, kit, event fee) | Versioned once published |
| `orders` | One per enrolment. Header with totals and status. | Status transitions only |
| `order_lines` | Fee items copied onto the order at creation | **Immutable after issue** |
| `payment_attempts` | Every attempt, online or offline, success or failure | Append-only |
| `receipts` | Issued documents with gapless sequential numbers | **Immutable** |
| `refunds` | Refund records linked to payments | Append-only |
| `credit_notes` | Issued when an order is reduced or cancelled after issue | **Immutable** |

Order lines are copied, not referenced, so a later fee change never rewrites history.

## F2. Order state machine

```
  Draft ──> Issued ──> Awaiting Payment ──> Partially Paid ──> Paid
    │          │              │                    │             │
    │          │              │                    │             ├──> Refunded
    │          │              │                    │             └──> Partially Refunded
    │          │              │                    │
    │          │              └──> Overdue ────────┘
    │          │                     │
    │          └──> Cancelled <──────┘
    └──> Voided (never issued)
```

- **Draft** — created with the enrolment, not yet payable (consent outstanding)
- **Issued** — consent signed; order becomes payable and lines lock
- **Overdue** — past payment deadline; triggers the reminder ladder, does not auto-cancel
- **Cancelled** — requires a reason; if any payment exists, a credit note is issued

## F3. Payment attempt state machine

```
  Initiated ──> Pending ──> Confirmed ──> [receipt issued]
      │            │            │
      │            │            └──> Reversed (chargeback / error correction)
      │            └──> Failed
      └──> Abandoned
```

**Offline payments enter directly at `Pending`** and are moved to `Confirmed` by an administrator. **Online payments (Phase 2)** move Initiated → Pending on redirect and Pending → Confirmed on verified gateway callback.

## F4. Offline payment workflow

```
  ADMIN                          SYSTEM
    │                               │
    │ opens Order                   │
    │ Record Payment                │
    ├──────────────────────────────>│
    │   method (bank/cash/cheque/   │
    │           FPS manual)          │
    │   amount, date received        │
    │   external reference           │
    │   evidence file (required)     │
    │   note                         │
    │                               │ CREATE payment_attempt (Pending)
    │                               │ store evidence to OSS
    │                               │ audit: recorded_by, at, IP
    │                               │
    │ Confirm                       │
    ├──────────────────────────────>│
    │                               │ attempt -> Confirmed
    │                               │ recalculate order total paid
    │                               │ order -> Partially Paid | Paid
    │                               │ if Paid: ISSUE RECEIPT
    │                               │ audit: confirmed_by, at
    │                               │ notify payer + student
```

**Segregation of duty:** the platform supports (and a programme may require) that the administrator who *confirms* differs from the one who *records*. Both identities are stored on the attempt.

## F5. Receipt rules

- **Numbering:** `KA-{YYYY}-{NNNNNN}`, gapless, allocated by a database sequence inside the same transaction that confirms payment. A gap is impossible; a duplicate is impossible.
- **Immutability:** once issued, a receipt is never edited. Corrections are made by credit note plus a new receipt.
- **Reprints:** unlimited, watermarked **DUPLICATE**, each reprint audit-logged with who and when.
- **Storage:** PDF written to OSS with a SHA-256 hash recorded on the receipt row so tampering is detectable.
- **Retention:** 7 years minimum. Hong Kong's Inland Revenue Ordinance s.51C requires business records be kept at least 7 years, with a maximum fine of HK$100,000 for non-compliance. Electronic records are acceptable provided they stay readable and retrievable for the whole period.
- **Content:** academy name and address, receipt number, issue date, payer name, student name, programme, itemised lines, total, currency, payment method, external reference. Hong Kong prescribes no statutory receipt template and has no VAT, so content follows commercial practice.

## F6. Refunds and credit notes

```
  Refund requested ──> Under Review ──> Approved ──> Processed ──> Credit Note issued
         │                    │                           │
         │                    └──> Rejected (reason)      └──> Failed ──> retry
         └──> Withdrawn
```

Refunds arising from withdrawals arrive **pre-filled by the E7 policy computation** — the reviewing admin confirms rather than calculates. Refunds never modify the original receipt. The credit note references it, and the order moves to Refunded or Partially Refunded. Net position always equals receipts minus credit notes — an invariant the nightly reconciliation asserts.

## F7. Gateway abstraction (Phase 2 readiness)

```
        Order (gateway-agnostic)
              │
              v
      ┌───────────────────┐
      │ PaymentProvider   │  interface
      │  - initiate()     │
      │  - verify()       │
      │  - refund()       │
      │  - reconcile()    │
      └─────┬───────┬─────┘
            │       │
   ┌────────┘       └────────┐
   v                         v
┌────────────────┐   ┌──────────────────┐
│ ManualProvider │   │  QFPayProvider   │
│  (Phase 1)     │   │  (Phase 2)       │
│ admin records  │   │ webhook + query  │
└────────────────┘   └──────────────────┘
```

Phase 2 adds a provider class and a webhook route. Orders, receipts, refunds and the ledger are untouched.

---

# PART G — CONSENT FORM & E-SIGNATURE (BUILT IN-HOUSE)

No third-party e-signature service. Everything below is built on the platform.

## G1. Legal basis

Hong Kong's **Electronic Transactions Ordinance (Cap. 553)** gives electronic signatures the same legal standing as handwritten ones for non-government transactions, provided the method used is **reliable, appropriate and agreed by the recipient**. The Ordinance defines an electronic signature broadly as letters, characters, numbers or other symbols in digital form attached to or logically associated with an electronic record, executed or adopted to authenticate or approve it.

The combination specified below — authenticated guardian session, full document display, explicit affirmation, captured signature, and a complete audit record — satisfies the reliability test.

**The consent wording itself should be reviewed by a Hong Kong-qualified lawyer before launch.** That is a legal review, not an engineering task.

## G2. Entities

| Entity | Purpose |
|---|---|
| `consent_templates` | Named template, owning programme (or global), status |
| `consent_template_versions` | HTML body, version number, SHA-256 hash, published date. **Immutable once published.** |
| `consent_requests` | One per enrolment per required signer; carries state and expiry |
| `consent_signatures` | The signed record with full audit evidence. **Immutable.** |
| `consent_documents` | Generated signed PDF, OSS path, hash |

## G3. Template authoring

The admin editor offers two modes on the same template:
- **Rich text** — WYSIWYG for non-technical staff
- **HTML source** — paste raw HTML for legally drafted text supplied by a solicitor

**Merge fields** use `{{variable}}` syntax, resolved at render time:

`{{student_name}}` · `{{student_name_zh}}` · `{{student_dob}}` · `{{guardian_name}}` · `{{programme_name}}` · `{{programme_period}}` · `{{organiser_name}}` · `{{fee_total}}` · `{{academy_name}}` · `{{today}}`

**Signature anchors** are reserved tokens the system replaces with real components:

| Token | Renders |
|---|---|
| `{{signature}}` | Signature capture pad (draw or type) |
| `{{signature_date}}` | Server-stamped date, not editable |
| `{{signer_name}}` | Signer's registered name, not editable |
| `{{initials}}` | Small initial box for per-clause acknowledgement |

A template cannot be published without at least one `{{signature}}` anchor — validated at publish.

## G4. Signing workflow

```
  SYSTEM                                    GUARDIAN
    │                                          │
    │ consent request created                  │
    │ notify (in-app + email) ────────────────>│
    │                                          │ opens secure link
    │                                          │ (must be authenticated)
    │ render template version, merge fields    │
    │ ───────────────────────────────────────> │
    │                                          │ reads
    │                                          │
    │  [Sign] disabled until scrolled to end   │
    │                                          │
    │                                          │ draws or types signature
    │                                          │ ticks affirmation checkbox
    │                                          │ clicks Sign
    │  <───────────────────────────────────────┤
    │                                          │
    │ CAPTURE:                                 │
    │   signature image (PNG)                  │
    │   server timestamp (NTP, not client)     │
    │   IP address, user agent                 │
    │   template version + SHA-256 hash        │
    │   signer user id, name, relationship     │
    │   event sequence (opened, scrolled,      │
    │                   signed) with times     │
    │                                          │
    │ GENERATE signed PDF:                     │
    │   rendered document                      │
    │   + signature block                      │
    │   + audit certificate page               │
    │ hash PDF, store to OSS                   │
    │                                          │
    │ consent_request -> Signed                │
    │ enrolment -> Payment Pending             │
    │ audit event written                      │
    │ notify guardian + student ──────────────>│
```

## G5. Consent state machine

```
  Draft ──> Sent ──> Viewed ──> Signed
              │        │           │
              │        │           └──> Superseded (re-consent required)
              │        └──> Declined (reason captured)
              └──> Expired ──> Resent
```

Declining is a first-class outcome, not a dead end. It records a reason, notifies the academy, and moves the enrolment to Declined so the seat is released immediately rather than waiting for the hold window.

## G6. Signature capture

Three methods, all built in-house with `react-signature-canvas` on a HTML5 canvas:

| Method | Stored as | When to use |
|---|---|---|
| **Drawn** | PNG (base64 → OSS) plus stroke vector data | Default; carries the most evidential weight |
| **Typed** | Rendered text in a script face plus the raw typed string | Accessibility fallback, mobile convenience |
| **Affirmation only** | Checkbox state | Only where programme config permits; weakest |

Affirmation checkbox is **always required** regardless of method. Stroke vector data is retained for drawn signatures because it evidences a human hand rather than a pasted image.

## G7. Versioning and re-consent

Every signature stores the template **version number and content hash**. If the template is edited:

- A **new version** is created; the old version is never modified
- Existing signatures remain valid against the version they signed
- The admin marks the change **material** or **non-material**
- Material changes generate fresh consent requests for all active enrolments and set prior consents to `Superseded`; enrolments move back to Consent Pending
- Non-material changes apply only to future enrolments

The Admin › Consent view always shows which enrolments are on which version, so a compliance question is answerable in one screen.

## G8. Audit certificate

Every signed PDF carries a final page recording: document title and version, SHA-256 of the version signed, signer name, account email, relationship to student, signature method, server timestamp (Asia/Hong_Kong with UTC offset), IP address, user agent, event sequence with timestamps, and the PDF's own hash. This page is what proves the signature if it is ever challenged.

---

# PART H — BULK ENROLMENT

School-sponsored enrolment where a School Admin enrols many students at once. Each student still requires individual guardian consent — that cannot be batched away.

## H1. Workflow

```
 SCHOOL ADMIN            SYSTEM                    GUARDIANS
      │                     │                          │
      │ upload CSV          │                          │
      ├────────────────────>│                          │
      │                     │ parse, validate每 row     │
      │  <── error report ──┤ (dedupe, format, age,    │
      │      per row        │  eligibility, capacity)  │
      │                     │                          │
      │ fix + re-upload     │                          │
      │ choose payer:       │                          │
      │  school | parent    │                          │
      │ Confirm             │                          │
      ├────────────────────>│                          │
      │                     │ CREATE batch             │
      │                     │ per row:                 │
      │                     │  - match/create student  │
      │                     │  - create guardian invite│
      │                     │  - create enrolment      │
      │                     │      (Consent Pending)   │
      │                     │  - create order          │
      │                     │                          │
      │                     │ dispatch invites ───────>│
      │                     │                          │ register/login
      │                     │                          │ link auto-activates
      │                     │                          │ (school vouched)
      │                     │                          │ sign consent
      │                     │  <───────────────────────┤
      │                     │                          │
      │  batch dashboard    │ IF payer = school:       │
      │  live per-row state │   consolidated invoice   │
      │  <──────────────────┤   one order per student, │
      │                     │   one invoice for batch  │
      │                     │ IF payer = parent:       │
      │                     │   individual orders      │
      │ [Chase outstanding] │                          │
      ├────────────────────>│ bulk reminder ──────────>│
```

## H2. Batch state machine

```
  Draft ──> Validating ──> Validated ──> Processing ──> Partially Complete ──> Complete
    │            │              │                              │
    │            └──> Failed    └──> Cancelled                 └──> Closed with exceptions
    └──> Abandoned                                                  (deadline reached)
```

A batch reaching its deadline with outstanding rows moves to **Closed with exceptions** rather than hanging. Outstanding enrolments expire individually and appear in an exceptions report.

## H3. Per-row state

Each row carries its own independent state, visible in the batch dashboard:

| Row state | Meaning |
|---|---|
| `account_pending` | Student account not yet created or matched |
| `guardian_pending` | Guardian invite sent, not accepted |
| `consent_pending` | Guardian linked, consent not signed |
| `payment_pending` | Consent signed, payment outstanding |
| `active` | Complete |
| `failed` | Validation or processing error, with reason |
| `expired` | Hold window elapsed |

## H4. Batch dashboard

```
┌──────────────────────────────────────────────────────────────┐
│  BATCH #B-2026-014 · STEM on Car · Bright Future Academy      │
│  38 rows · payer: School · deadline 15 Aug                    │
├──────────────────────────────────────────────────────────────┤
│  ████████████████████████░░░░░░░░░░░░░░░░       24 of 38     │
│                                                               │
│  Active                24  ████████████████                   │
│  Payment pending        6  ████                               │
│  Consent pending        5  ███                                │
│  Guardian pending       2  █                                  │
│  Failed                 1  ▌                                  │
├──────────────────────────────────────────────────────────────┤
│  [ Chase consents (5) ] [ Chase guardians (2) ] [ Export ]    │
└──────────────────────────────────────────────────────────────┘
```

## H5. Payment arrangements

| Payer | Mechanism | Receipt |
|---|---|---|
| **School** | One order per student for audit granularity, one consolidated invoice for the batch. School Admin pays or the academy records offline settlement. | One receipt to the school, itemised by student |
| **Parent** | Individual orders, each parent pays their own | One receipt per parent |
| **Mixed** | Batch setting per row — a school subsidy plus a parent contribution | Split receipts, both referencing the same order |

---

# PART I — DASHBOARDS

## I1. Architecture

- **Widget-based**, rendered on a 12-column responsive grid via **react-grid-layout**
- **Academy Admin defines role presets** — the default layout every holder of a role sees
- **Users personalise within their role's permitted widget set** — rearrange, resize, hide, plus **Reset to default**
- Layout persisted as JSON per user per role
- **Global filter bar** (date range, programme, cohort) with optional per-widget override
- Widgets lazy-load with skeletons; aggregates are served from cached, trigger-maintained tables refreshed on a schedule, not computed live per view

## I2. Charting

**Ant Design Charts (@ant-design/charts, built on AntV G2Plot)** is the default so every chart inherits the brand tokens. **Apache ECharts** is reserved for dense visualisations — AR/AP aging heatmaps and cohort scatter plots beyond roughly 10,000 points — where canvas rendering outperforms SVG.

## I3. Chart type to metric mapping

| Metric | Chart | Notes |
|---|---|---|
| Enrolment trend over time | Line (continuous) / Column (per term) | Never pie |
| Single completion percentage | Progress ring | One value only |
| Activity Tracker progress | Stepped progress bar + milestone timeline | Five fixed stages |
| Team workflow state | Kanban | Plan → Design → Learn → Pitch → Launch |
| Attendance over time | Line | By cohort, multi-series max 5 |
| Attendance by class | Horizontal bar | Sorted descending |
| Revenue actual vs target | Column + target line, or bullet chart | Bullet is denser |
| Cumulative revenue | Area | |
| Cohort comparison | Grouped bar | |
| Engagement day × hour | Heatmap | Sequential palette |
| Team finance composition | Stacked bar, or donut if ≤5 slices | |
| AR aging | Stacked bar by bucket | 0-30 / 31-60 / 61-90 / 90+ |
| Enrolment funnel | Funnel, or clean horizontal bar | |
| Student progress across cohort | Matrix / heat grid | At-risk identification |

**Avoid:** pie for time series, treemaps for few categories, 3-D effects, more than 8 series on one line chart.

## I4. Colour and accessibility

Congenital colour-vision deficiency affects up to 8% of males of Northern-European descent and around 0.5% of females. Categorical series therefore use the **Okabe-Ito palette** — orange `#E69F00`, sky blue `#56B4E9`, bluish green `#009E73`, yellow `#F0E442`, blue `#0072B2`, vermillion `#D55E00`, reddish purple `#CC79A7`, black `#000000`. Sequential heatmaps use **Viridis**; diverging scales use **RdBu**.

Brand colours (aubergine, gold) are used for chrome, emphasis and single-series charts. They are **not** stretched into a categorical palette. Meaning is never encoded by colour alone — every series carries a label, icon or pattern.

## I5. Role dashboards

### Student
| Widget | Visual | Purpose |
|---|---|---|
| Activity Tracker progress | Stepped bar, 5 stages | Where am I |
| Enrolment status alerts | Alert cards | Consent or payment blocking |
| My Team | Card with roster avatars, my role | Belonging |
| Next 3 sessions | List with countdown | What's next |
| Attendance to threshold | Progress ring | Learn gate proximity |
| Team budget snapshot | Stacked bar, budget vs actual | Finance awareness |
| Badges earned | Badge strip | Motivation |
| Announcements | Feed | |

### Parent / Guardian
| Widget | Visual | Purpose |
|---|---|---|
| Child selector | Segmented control | Multi-child households |
| Action required | Alert cards | **Consent to sign, payment due — always first** |
| Child progress | Progress ring per programme | At a glance |
| Payments & receipts | Table with download | Financial record |
| Upcoming sessions | List | Logistics |
| Attendance | Line, term to date | Engagement |
| Announcements | Feed | |

### Teacher
| Widget | Visual | Purpose |
|---|---|---|
| Approval queue | Counter cards by type | **Primary action surface** |
| Teams at risk | List with reason chips | Below minimum, leaderless, stalled gate |
| Today's sessions | Timeline | Attendance to record |
| Review queue | Counter + list | Deliverables awaiting |
| Student progress matrix | Heat grid, students × stages | At-risk identification |
| Attendance completion | Progress ring | Admin hygiene |
| Announcements | Feed | |

### School Administrator
| Widget | Visual | Purpose |
|---|---|---|
| Enrolment funnel | Funnel | Intent → consent → paid → active |
| Consent completion | Progress ring + outstanding count | Chase list |
| Payment status | Stacked bar | Paid / partial / overdue |
| Batch status | Progress bars per active batch | Bulk enrolment health |
| Cohort progress | Grouped bar by programme | Comparison |
| AR aging (school-scoped) | Stacked bar | Money owed |
| Teacher activity | Table | Approvals outstanding per teacher |

### Academy Administrator
| Widget | Visual | Purpose |
|---|---|---|
| Revenue actual vs target | Column + target line | Commercial headline |
| Enrolment trend | Line, 12 months | Growth |
| AR aging | Stacked bar by bucket | Collections |
| AP aging | Stacked bar | Obligations |
| Programme performance | Grouped bar — enrolled, completion, revenue | Which programmes work |
| At-risk overview | Heat grid across programmes | Intervention |
| Consent compliance | Progress ring | **Regulatory exposure** |
| Reconciliation alerts | Alert list | **Close-loop integrity** |
| System health | Status tiles — queues, failed jobs, sync | Operational |
| Notification delivery | Line, sent vs delivered | Comms health |

## I6. Dashboard data flow

```
  Source tables (orders, enrolments, attendance, consents, gates, ledger)
        │
        │ database triggers on write
        v
  Aggregate tables (pre-computed, trigger-maintained)
        │
        │ scheduled refresh + on-demand invalidation
        v
  Dashboard API (role-scoped at the query layer, never in the UI)
        │
        │ JSON, one call per widget, parallel
        v
  Widget components (lazy-loaded, skeleton while pending)
        │
        v
  react-grid-layout canvas (user layout JSON applied)

  ── nightly ──> Reconciliation job asserts aggregate == source
                 mismatch raises alert on Academy Admin dashboard
```

This is what makes "100% accuracy" real: aggregates are never hand-maintained, and every one has a nightly assertion proving it still equals its source.

---

# PART J — REPORTING, AR & AP

## J1. Standard report catalogue

| Domain | Reports |
|---|---|
| **Enrolment** | Enrolments by programme · by school · by status · conversion funnel · withdrawals · waitlist |
| **Consent** | Outstanding consents · signed by version · declined · expired · re-consent required |
| **Progress** | Activity Tracker stage completion · gate pass rates · at-risk students · team progress |
| **Attendance** | By student · by session · by programme · threshold shortfall |
| **Teams** | Roster · role assignment · rotation history · exceptions |
| **Finance** | Revenue by programme · outstanding · refunds · receipts issued · payment method mix |
| **AR / AP** | See J3, J4 |
| **Recognition** | Badges issued · certificates issued · portfolio completion |
| **Audit** | State transitions · access log · consent evidence · reconciliation results |

## J2. Custom report builder (no SQL)

```
  1. Choose subject        Students | Enrolments | Orders | Teams | Sessions
           v
  2. Pick columns          drag from field library, reorder
           v
  3. Build filters         visual condition builder, AND/OR groups
           v
  4. Group & sort          group by, aggregate (count/sum/avg), sort
           v
  5. Preview               live, first 50 rows
           v
  6. Save                  name, share scope (private/role/all)
           v
  7. Schedule (optional)   frequency, format, recipients
```

**Role scoping is applied server-side at the query layer, never in the report definition.** A teacher building a report on "all students" receives only their assigned students. The report definition is identical for everyone; the result set is not. This is the only safe way to let non-admins build reports.

## J3. Accounts Receivable

| Report | Contents |
|---|---|
| **AR Aging Summary** | Outstanding by customer, bucketed 0-30 / 31-60 / 61-90 / 90+ days |
| **AR Aging Detail** | Line-level, every unpaid order line with age |
| **Outstanding by Customer** | Parent or school, total owed, oldest item |
| **DSO** | AR balance ÷ credit sales × days in period, trended |
| **Collection Status** | Reminder ladder position per overdue order |
| **Customer Statement** | Per parent or school: orders, payments, receipts, balance |
| **Revenue Recognition** | Paid vs earned across programme delivery periods |

Aging is computed **live from the ledger** so it always reflects current order and payment status.

## J4. Accounts Payable

| Report | Contents |
|---|---|
| **AP Aging Summary** | Owed by vendor, bucketed |
| **AP Aging Detail** | Line-level |
| **Vendor Balance Detail** | Per mentor, organiser, venue |
| **Commitment Report** | Contracted but not yet invoiced |

Vendors are mentors (session fees), external organisers (programme licence fees), and venues.

## J5. Export and scheduling
Formats: PDF, XLSX, CSV. Scheduled reports run as queued jobs, deliver by email, and log delivery. A failed scheduled report raises an alert rather than failing silently.

---

# PART K — NOTIFICATION ENGINE

## K1. Event catalogue

| Event | Trigger | Recipients | Channels | Timing |
|---|---|---|---|---|
| `enrolment.created` | Enrolment created | Student, Guardian | In-app, Email | Immediate |
| `consent.requested` | Consent request issued | Guardian | In-app, Email, WhatsApp | Immediate |
| `consent.reminder` | Outstanding | Guardian | Email, WhatsApp | +3d, +5d, +6d |
| `consent.signed` | Signature recorded | Student, Guardian, School Admin | In-app, Email | Immediate |
| `consent.declined` | Guardian declined | Academy Admin, School Admin | In-app, Email | Immediate |
| `consent.expiring` | Hold window closing | Guardian, Student | Email | −24h |
| `consent.resupersede` | Material template change | All affected Guardians | In-app, Email | Immediate |
| `order.issued` | Consent signed, order payable | Payer | In-app, Email | Immediate |
| `payment.reminder` | Payment outstanding | Payer | Email, WhatsApp | −7d, −3d, −1d |
| `payment.overdue` | Past deadline | Payer, School Admin | Email | +1d, +7d, +14d |
| `payment.received` | Payment confirmed | Payer, Student | In-app, Email | Immediate |
| `receipt.issued` | Receipt generated | Payer | Email (PDF attached) | Immediate |
| `refund.processed` | Refund completed | Payer | In-app, Email | Immediate |
| `enrolment.active` | Enrolment complete | Student, Guardian | In-app, Email | Immediate |
| `team.invite` | Join request received | Team leader | In-app | Immediate |
| `team.request_result` | Approved or declined | Requesting student | In-app | Immediate |
| `team.formation_submitted` | Leader submitted | Authorizer | In-app, Email | Immediate |
| `team.formation_result` | Approved or rejected | All members | In-app, Email | Immediate |
| `team.below_minimum` | Member departure drops team | Leader, Authorizer | In-app, Email | Immediate |
| `role.rotation_due` | Rotation scheduled | Team members | In-app | −3d, day of |
| `gate.submitted` | Stage gate submitted | Approver | In-app, Email | Immediate |
| `gate.result` | Passed or returned | Team | In-app, Email | Immediate |
| `budget.submitted` | Budget for approval | Approver | In-app, Email | Immediate |
| `budget.result` | Approved or changes requested | Finance Manager, Leader | In-app, Email | Immediate |
| `transaction.submitted` | Expense for approval | Approver | In-app | Batched daily |
| `session.reminder` | Lesson or event upcoming | Booked students, Guardians | In-app, Email | −24h, −1h |
| `session.cancelled` | Session cancelled | Booked students, Guardians | In-app, Email, SMS | Immediate |
| `session.rescheduled` | Session datetime changed | Booked students, Guardians | In-app, Email, SMS | Immediate |
| `guardian.replacement_required` | Sole-guardian link revoked (B8) | Academy Admin, School Admin | In-app, Email | Immediate |
| `enrolment.withdrawal_result` | Withdrawal approved/rejected | Student, Guardian | In-app, Email | Immediate |
| `waitlist.promoted` | Seat released | Promoted student, Guardian | In-app, Email | Immediate |
| `attendance.shortfall` | Below Learn threshold | Student, Guardian, Teacher | In-app, Email | Weekly |
| `badge.earned` | Badge criteria met | Student, Guardian | In-app | Immediate |
| `certificate.issued` | Certificate generated | Student, Guardian | In-app, Email | Immediate |
| `student.at_risk` | Risk rule triggered | Teacher, School Admin | In-app | Daily digest |
| `batch.completed` | Bulk enrolment finished | School Admin | In-app, Email | Immediate |
| `batch.exceptions` | Batch closed with outstanding | School Admin, Academy Admin | Email | On close |
| `sync.failed` | Organiser sync dead-lettered | Academy Admin | In-app, Email | Immediate |
| `reconciliation.mismatch` | Nightly assertion failed | Academy Admin | In-app, Email | Immediate |

## K2. Template system

Templates use **Handlebars `{{variable}}` syntax**, the de-facto standard across major delivery platforms. Each event has its own template per channel per language (EN / 繁中 / 简中).

**System variable groups:**

| Group | Variables |
|---|---|
| Student | `{{student_name}}` `{{student_name_zh}}` `{{student_email}}` `{{student_grade}}` |
| Guardian | `{{guardian_name}}` `{{guardian_email}}` |
| Programme | `{{programme_name}}` `{{programme_period}}` `{{organiser_name}}` `{{stage_name}}` |
| Team | `{{team_name}}` `{{team_role}}` `{{leader_name}}` |
| Money | `{{amount}}` `{{currency}}` `{{due_date}}` `{{receipt_no}}` `{{order_no}}` |
| Session | `{{session_title}}` `{{session_datetime}}` `{{session_location}}` `{{mentor_name}}` |
| Action | `{{consent_link}}` `{{payment_link}}` `{{portal_link}}` |
| System | `{{academy_name}}` `{{today}}` `{{support_email}}` |

Defaults are supported (`{{guardian_name | "Parent/Guardian"}}`), as are conditionals and iteration for list content. The template editor shows a live variable picker and renders a preview against sample data. **Publishing is blocked if a template references an undefined variable** — this prevents a broken merge field reaching a parent.

## K3. Preference centre

A **category × channel matrix** per user:

```
                        In-app  Email  WhatsApp  SMS
  Enrolment & Consent     [x]    [x]     [x]     [ ]   ← locked, transactional
  Payments & Receipts     [x]    [x]     [ ]     [ ]   ← locked, transactional
  Team Activity           [x]    [x]     [ ]     [ ]
  Sessions & Reminders    [x]    [x]     [x]     [ ]
  Progress & Achievement  [x]    [ ]     [ ]     [ ]
  Announcements           [x]    [x]     [ ]     [ ]
```

Three layers: system defaults → role defaults → user overrides. **Transactional categories (consent, payment, receipts, cancellations) cannot be disabled** — they carry legal and financial weight.

## K4. Delivery pipeline

```
  Domain event raised
        │
        v
  Notification rule engine
    - match event to rule
    - resolve recipients (role + relationship)
    - apply user preferences
    - apply quiet hours (Asia/Hong_Kong)
    - deduplicate (same event + recipient + 5 min window)
        │
        v
  Queue (Redis + Horizon), one job per recipient per channel
        │
        v
  Channel adapter (In-app | Email | WhatsApp | SMS | WeChat)
        │
        ├── success ──> delivery_log: sent, provider id
        ├── soft fail ──> retry with backoff (max 3)
        └── hard fail ──> dead letter ──> admin alert
        │
        v
  Delivery status webhook (where the provider supports it)
        │
        v
  delivery_log updated: delivered | bounced | opened
```

## K5. Reminder ladders

Ladders are configured per event, not hard-coded:

| Ladder | Schedule | Escalation |
|---|---|---|
| Consent outstanding | +3d, +5d, +6d | Day 7: notify School Admin |
| Payment due | −7d, −3d, −1d | Overdue +14d: notify School Admin |
| Session | −24h, −1h | None |
| Gate stalled | +7d, +14d | Day 21: notify Academy Admin |
| Guardian link pending | +2d, +5d | Day 7: expire, notify student |

A ladder stops the moment the underlying condition clears. **Every send is logged so the academy can prove a parent was notified** — which matters if a consent or payment dispute arises.

## K6. Quiet hours and fatigue
Non-urgent notifications are suppressed 21:00–08:00 Asia/Hong_Kong and queued for the next window. Urgent categories (session cancelled, payment failed) override. A per-user daily cap folds overflow into a digest.

---

# PART L — BRANDING, POSITIONING & THEMING

## L1. The holding company — confirmed

**king-armour.com is King Armour Family Office**, a prestigious affiliate of **Sunwah Kingsway Capital Holdings Limited** (established 1957, SEHK: 188). Headquartered in Hong Kong with offices in **Beijing, Shanghai, Shenzhen, Toronto, Vietnam, Cambodia and Singapore**.

- **Tagline:** *Fortify Your Growth, Armour Your Assets, Unite Generations*
- **Three pillars:** **Thrust Forward** (unlocking growth) · **Stand Guard** (risk management) · **Pass the Torch** (generational continuity)
- **Clientele:** ultra-high-net-worth families

## L2. Where KA Playground actually sits

```
  KING ARMOUR FAMILY OFFICE
        │
        └── KINGS NETWORK
              ├── Members Only Events  ── quarterly, FIRST generation
              │                            (asset owners)
              │
              ├── GLOBAL ELITE SUMMER PROGRAM ── SECOND generation
              │     │                              (client families' children)
              │     ├── Social        networking, lasting peer relationships
              │     ├── Charity       hands-on philanthropic projects
              │     ├── Informational advanced workshops, seminars,
              │     │                 financial education, market insights
              │     └── Mentorship    personalised guidance from
              │                       seasoned leaders, future stewardship
              │
              └── Event
```

**KA Playground is the digital platform for the Global Elite Summer Program.** The programme is explicitly described as *"designed for the second generation, mirroring the elite private wealth initiatives that have proven successful over time"* and intended to *"nurture future stewardship."*

## L3. Why this reframes the product

This is **not a mass-market education platform**. It is a private, members-facing portal for the children of ultra-high-net-worth client families. Six consequences:

| Dimension | Implication |
|---|---|
| **Audience** | Children of UHNW families, referred through the family office. Not public sign-up. |
| **Scale** | Small, curated cohorts. Performance and capacity requirements are modest; the PIPL 10,000-individual threshold is unlikely to be reached. |
| **Tone** | Understated luxury and discretion. **Not playful edtech.** No gamified confetti, no leaderboards ranking one family's child against another's. |
| **Privacy** | Client confidentiality sits *on top of* PDPO/PIPL. Which families participate, and who their children are, is itself sensitive commercial information. |
| **Visibility defaults** | Team browsing, peer directories and public catalogues must default to **closed**. See L4. |
| **Geography** | Eight jurisdictions, not one. See L5. |

## L4. Privacy posture — revised upward

The earlier specification assumed a semi-public education platform. For a family-office context the defaults invert:

| Setting | Previous default | **Revised default** |
|---|---|---|
| Programme catalogue | Public browsing | **Authenticated members only** |
| Student registration | Self-service | **Invitation only, issued by the academy** |
| Team visibility | `category` (all enrolled in category) | **`private`** — invite only, with `category` as an opt-in |
| Peer directory | Visible to all enrolled | **Off** unless explicitly enabled per programme |
| Student real names to peers | Shown | **Configurable** — first name plus initial as default |
| Avatar uploads | Optional with moderation | **Library only.** No custom photographs of minors from UHNW families. |
| Public certificate verification | Open URL | **Token-gated URL**, no name disclosed without the token |
| Badges and achievements | Visible to peers | **Visible to self, guardian and staff only** by default |

Self-registration should be replaced by an **invitation-only onboarding flow**: the academy issues an invitation to a family, the guardian registers first, then creates or links the student account. This inverts the guardian-linking flow in Part B — the guardian is the anchor, not the student — which is also a better fit for the consent model, since the guardian already exists before any enrolment is attempted.

**This is a recommended change to Part B4 and should be confirmed before build.**

## L5. Multi-jurisdiction reality

The programme runs across **Hong Kong, mainland China (Beijing, Shanghai, Shenzhen), Canada, Vietnam, Cambodia and Singapore**. Earlier versions of this specification modelled only Hong Kong plus a UK organiser. That is now insufficient.

| Requirement | Impact |
|---|---|
| **Timezones** | Session scheduling, reminder ladders and quiet hours must resolve per user, not per platform. Store all timestamps in UTC, render per user preference. |
| **Languages** | English, Traditional Chinese, Simplified Chinese at minimum. Vietnamese worth scoping. |
| **Data residency** | PIPL (mainland), PDPO (HK), PIPEDA (Canada), PDPA (Singapore), PDPD (Vietnam), plus Cambodia. Alibaba Cloud HK remains the right origin, but the cross-border transfer register in Part P must record destination jurisdiction per transfer, not just "overseas". |
| **Currency** | HKD primary; consider CNY, SGD, CAD, USD display. Fee items already carry a currency field. |
| **Event locations** | Sessions need a location and timezone, not just a datetime. |

**Recommendation:** treat this as a Phase 1 schema concern (timezone and jurisdiction fields present from day one) and a Phase 2 feature concern (actual localisation and multi-currency settlement).

## L6. Component mapping — one gap found

| Summer Program component | Platform module | Status |
|---|---|---|
| **Social** | Learn › Events | Specified |
| **Informational** | Learn › Lessons (workshops, seminars, webinars) | Specified |
| **Mentorship** | Learn › Mentors + Mentor Classes | Specified |
| **Charity** | — | **NOT MODELLED** |

**Charity is a genuine gap.** *"Hands-on philanthropic projects that instill a deep sense of social responsibility and community impact"* is a first-class component of the programme, not an add-on.

Two options:
1. **Reframe the Pitch stage** to cover both commercial sponsorship and philanthropic fundraising, with a `project_type` of `sponsorship | charity` on team deliverables and finance records
2. **Add a Charity module** with its own project records, beneficiary tracking, hours logged and impact reporting

**Recommendation: option 1 for Phase 1.** The Pitch stage already models proposals, agreements, funds and deliverables. A charity project is structurally the same objects with a different intent and a beneficiary instead of a sponsor. Option 2 becomes worthwhile only if the academy needs impact reporting across programmes.

**Needs client confirmation.**

## L7. Does the platform also serve the first generation?

Kings Network also runs **Members Only Events** for first-generation asset owners, quarterly. The specification currently models only the second-generation programme.

If the platform is to serve both, it needs a sixth role — **Member** (a first-generation client) — with access to event listings, RSVP and a members directory, but none of the team, Activity Tracker or finance modules. That is a modest addition if planned now and an awkward retrofit later.

**Needs client confirmation.**

## L8. Brand assets — what is actually available

| Asset | Status |
|---|---|
| Logo | `KA-Logo-BW-Black-01` — **monochrome black only**. No colour variant published. |
| Photography | Professional image set (`KingArmour800-*`, `KingArmour1920-*`, WebP). Consistent, premium, editorial. |
| Palette | **Not published.** No brand hex values available on the site. |
| Typeface | **Not published.** |
| Brand guidelines / media kit | **Not published.** |

The site runs WordPress 7.0.2 and still carries **unedited theme placeholder content** — the header shows `info@yourwebsite.com` and `+1 (555) 123-4567` (the footer has the real `info@king-armour.com`), and an entire "Research & Technology / advanced biochemical technologies / Founded in 2018" section remains from the theme demo. The site is evidently still in build.

**Still required from the client:** logo vector in colour and mono, exact hex values, typeface licences, and any brand guideline document.

## L9. Recommended palette — direction validated, register adjusted

The monochrome logo gives no palette to extract, but the brand character now argues clearly for the direction already established: **"Armour," heraldic naming, 1957 lineage, generational stewardship, UHNW clientele.** Deep aubergine with gold reads as heritage, protection and prestige — precisely the brand's own language.

What changes is **register**: more restraint than a consumer education product. Gold is an accent for emphasis and state, never a large fill.

| Token | Hex | Use |
|---|---|---|
| Aubergine (primary) | `#1A1326` | Chrome, sidebar, primary buttons, headings |
| Aubergine deep | `#0F0B15` | Dark-mode background |
| Gold (accent) | `#C9A962` | Active states, focus rings, badges, emphasis — **accent only** |
| Gold light | `#D9BE81` | Hover on gold |

**Category accents** (programme colour coding): Language `#6366F1` · STEM `#A855F7` · Arts `#EC4899` · Maths `#F97316` · Featured `#06B6D4`

**Semantic:** success `#22C55E` · warning `#F59E0B` · danger `#EF4444`

**Typography:** Headings **Montserrat** (700/800) · Body **Inter** (400/500) · Traditional Chinese **Noto Sans HK** — full component-level system in **KA_Playground_Design_System_v2_AntD.md**, which is the S00 source of truth

**Tone rules that follow from the positioning:**
- No confetti, no streak counters, no peer leaderboards
- Progress shown as quiet, precise indicators — thin rings and stepped bars, not oversized gamified meters
- Photography-led surfaces, matching the site's editorial image style
- Generous whitespace; density reserved for admin screens
- Gold used sparingly enough that its appearance always means something

## L10. Ant Design 5 token strategy

Ant Design 5 replaced LESS variables with a CSS-in-JS token system in three layers — **seed → map → alias** — configured through `ConfigProvider`. It supports three composable algorithms (`defaultAlgorithm`, `darkAlgorithm`, `compactAlgorithm`), and **dark mode and a custom brand palette can be applied simultaneously** by passing `darkAlgorithm` alongside custom seed tokens.

| Layer | Setting |
|---|---|
| Seed | `colorPrimary` → aubergine · `colorInfo` → aligned · `borderRadius` → 10 · `fontFamily` → DM Sans stack |
| Algorithm | `defaultAlgorithm` light, `darkAlgorithm` dark, user-toggleable |
| Component tokens | `Menu` (aubergine sider, gold active indicator) · `Button` (gold CTA variant) · `Layout` · `Tag`, `Badge` · `Steps` (gold active) · `Table` (row hover) |
| Runtime | `cssVar: true` for theme switching without re-render, `hashed: false` (single antd version) |

**Two pitfalls to design around:**

1. **Static methods do not inherit the theme.** `message.*`, `notification.*` and `Modal.*` called statically render in a separate React root and ignore `ConfigProvider`. Wrap the app in antd's `App` component and access them via `App.useApp()`, or every toast and confirm dialog renders in default blue.
2. **Charts do not inherit tokens.** Ant Design Charts must be given a shared theme object built from the same tokens, or dashboards drift visually from the shell.

## L11. Ant Design Pro layout
ProLayout supplies the desktop shell — sider, header, breadcrumb, multi-tab — themed via component tokens plus a custom logo slot. Aubergine sider with gold active indicators; content area deep aubergine — dark mode is the sole theme. **Below 768px the shell switches to the app-like model in Design System §17: bottom tab bar (5 per role) + edge-swipe navigation drawer — no hamburger — with bottom sheets, swipe-to-close and a PWA manifest.**

---

# PART M — DATA FLOW & RELATIONSHIP MAPS

## M1. Master entity relationship map

```
                         ┌──────────────┐
                         │  PROGRAMME   │
                         │  (config)    │
                         └──────┬───────┘
        ┌───────────────┬───────┼────────┬──────────────┬─────────────┐
        v               v       v        v              v             v
  ┌───────────┐  ┌──────────┐ ┌────┐ ┌─────────┐ ┌───────────┐ ┌───────────┐
  │ FEE_ITEMS │  │ CONSENT  │ │STAG│ │TEAM_RULE│ │ROLE_LIBRARY│ │ CERT_RULE │
  │           │  │ TEMPLATE │ │ ES │ │         │ │            │ │           │
  └─────┬─────┘  └────┬─────┘ └─┬──┘ └────┬────┘ └─────┬──────┘ └─────┬─────┘
        │             │         │         │            │              │
        │             │         │         │            │              │
  ┌─────v─────────────v─────────v─────────v────────────v──────────────v─────┐
  │                          ENROLMENT                                      │
  │  student_id · programme_id · status · hold_expires_at                   │
  └──┬──────────────┬───────────────┬────────────────────┬──────────────────┘
     │              │               │                    │
     v              v               v                    v
 ┌────────┐  ┌─────────────┐  ┌──────────┐        ┌──────────────┐
 │ ORDER  │  │CONSENT_REQ  │  │TEAM_MEMB │        │ATTENDANCE    │
 └───┬────┘  └──────┬──────┘  └────┬─────┘        └──────┬───────┘
     │              │              │                     │
     v              v              v                     v
 ┌────────┐  ┌─────────────┐  ┌──────────┐        ┌──────────────┐
 │ORDER_  │  │CONSENT_SIG  │  │  TEAM    │        │  SESSION     │
 │LINES   │  │ (immutable) │  │          │        │ (lesson/event)│
 └───┬────┘  └──────┬──────┘  └────┬─────┘        └──────────────┘
     │              │              │
     v              v              ├──> TEAM_ROLES ──> ROLE_TENURE ──> BADGE
 ┌────────┐  ┌─────────────┐       ├──> DELIVERABLES
 │PAYMENT_│  │CONSENT_DOC  │       ├──> BUDGET ──> TRANSACTIONS
 │ATTEMPT │  │  (PDF+hash) │       └──> STAGE_GATES
 └───┬────┘  └─────────────┘
     v
 ┌────────┐     ┌─────────┐
 │RECEIPT │────>│ REFUND  │────> CREDIT_NOTE
 │(seq no)│     └─────────┘
 └────────┘

  ┌─────────┐   guardian link (MANDATORY)   ┌──────────┐
  │ STUDENT │<──────────────────────────────>│  PARENT  │
  └────┬────┘                                └──────────┘
       │  school link          teacher link
       ├────────────> SCHOOL <──────────────> TEACHER
       │
       └──> USER_ACCOUNT ──> ROLE ──> PERMISSIONS (+ per-link overrides)

  ══════════════════════════════════════════════════════════════
  EVERY table above writes to ──> AUDIT_EVENTS (append-only)
  ══════════════════════════════════════════════════════════════
```

## M2. Enrolment data flow (end to end)

```
 [1] Student clicks Enrol
       │
       ├─ read: guardian_links (must have >=1 active)
       ├─ read: programme (published? window open?)
       ├─ read: enrolment capacity vs current count
       │
 [2] WRITE enrolment (Consent Pending) ──> audit_events
     WRITE order (Draft) + order_lines (copied from fee_items)
     WRITE consent_request (Sent), version pinned + hashed
     SET hold_expires_at = now + hold_window
       │
 [3] Notification engine ──> guardian (in-app, email, WhatsApp)
       │
 [4] Guardian signs
     WRITE consent_signature (immutable) ──> audit_events
       ├─ capture: sig image, timestamp, IP, UA, version hash, event seq
     GENERATE consent_document (PDF + SHA-256) ──> OSS
     UPDATE enrolment ──> Payment Pending
     UPDATE order ──> Issued (lines now locked)
       │
 [5] Payment
     ┌── online (Phase 2) ────────┐   ┌── offline (Phase 1) ──────────┐
     │ WRITE payment_attempt      │   │ Admin records                 │
     │ redirect ──> gateway       │   │ WRITE payment_attempt(Pending)│
     │ callback verified          │   │ + evidence file ──> OSS       │
     │ attempt ──> Confirmed      │   │ Admin confirms                │
     └────────────────────────────┘   │ attempt ──> Confirmed         │
                    │                 └───────────────────────────────┘
                    v
 [6] Recalculate order paid total
     IF fully paid:
       ALLOCATE receipt_no from DB sequence (same transaction)
       GENERATE receipt PDF + hash ──> OSS
       UPDATE order ──> Paid
       UPDATE enrolment ──> Active
       ──> audit_events (every step)
       │
 [7] Notification ──> student + guardian: enrolment confirmed
     Activity Tracker unlocks
       │
 [8] Aggregates refresh (triggers) ──> dashboards
     Nightly reconciliation asserts:
       receipts total == confirmed payments total
       enrolment count == active enrolments
       consent coverage == active enrolments
```

## M3. Money data flow

```
  PROGRAMME FEES (real money)          TEAM FUNDS (record only)
  ────────────────────────────         ────────────────────────────
  fee_items                            budget (planned)
      │                                    │
      v                                    v
  order + order_lines                  transactions (income/expense)
      │                                    │  + receipt uploads
      v                                    v
  payment_attempts                     approval chain
   ├─ online (gateway, Ph2)             (student → Finance Mgr → teacher)
   └─ offline (admin recorded)              │
      │                                     v
      v                                 verification
  receipts (sequential, immutable)      (verified against offline reality)
      │                                     │
      ├──> refunds ──> credit_notes         v
      │                                 team P&L report
      v                                 (portfolio evidence)
  AR ledger ──> aging, DSO,
                statements

  ╔══════════════════════════════════════════════════════════════╗
  ║  These two flows NEVER mix. Team funds never enter the        ║
  ║  academy's receivables. Programme fees never appear in a      ║
  ║  team's P&L.                                                  ║
  ╚══════════════════════════════════════════════════════════════╝
```

## M4. Notification data flow

```
  Domain event (any state machine transition)
        │
        v
  notification_rules  ──lookup──> event type match
        │
        v
  Recipient resolution
    role + relationship graph
    (e.g. consent.requested ──> all active guardians of this student)
        │
        v
  Preference filter (user matrix; transactional bypasses)
        │
        v
  Quiet hours + dedupe window
        │
        v
  Queue: one job per recipient per channel
        │
        v
  Channel adapters ──> delivery_log (sent | delivered | bounced | opened)
        │
        └─ hard failure ──> dead letter ──> Academy Admin alert
```

## M5. Reporting data flow

```
  Source of truth tables
        │
        ├──> triggers ──> aggregate tables ──> dashboard widgets
        │                       ^
        │                       └── nightly reconciliation assertion
        │
        └──> report engine
                │
                ├── standard reports (predefined queries)
                └── custom reports (builder-generated queries)
                        │
                        v
                ROLE SCOPE FILTER (server-side, always applied)
                        │
                        v
                Result set ──> render | export (PDF/XLSX/CSV)
                        │
                        └──> scheduled ──> queue ──> email ──> delivery_log
```

## M6. Approval workflow map (all approval types share one engine)

```
  Submitter                Engine                    Approver
      │                       │                          │
      │ submit                │                          │
      ├──────────────────────>│                          │
      │                       │ resolve approver from    │
      │                       │ programme config         │
      │                       │ WRITE approval_request   │
      │                       │ notify ─────────────────>│
      │                       │                          │ opens queue
      │                       │  <───────────────────────┤ approve/return
      │                       │ WRITE decision + reason  │
      │                       │ ──> audit_events         │
      │                       │ update source entity     │
      │  <── notify ──────────┤                          │
      │                       │                          │
  Applies to: team formation · stage gates · budgets ·
              transactions · deliverables · refunds ·
              consent template publication
```

One engine, six consumers. Approver resolution, escalation ladders, audit and the queue UI are written once.

---

# PART N — SCHEMA SUMMARY

Core tables by module. Every table carries `id`, `created_at`, `updated_at`, `created_by`, `updated_by` unless marked immutable (immutable tables carry `created_at` and `created_by` only).

## N1. Identity & relationships
`users` · `roles` · `permissions` · `role_permissions` · `user_roles`
`students` · `guardians` · `teachers` · `schools`
`guardian_links` (student_id, guardian_id, status, verified_at, permission_overrides JSONB)
`school_links` · `teacher_links`
`pairing_codes` (student_id, code, expires_at, used_at, max 5 active)

## N2. Programme configuration
`programmes` (status, enrolment window, hold_window_days, payer_party, withdrawal policy fields per E7)
`programme_versions` (locked config snapshot per published version)
`fee_items` · `programme_stages` (5 fixed, config per programme)
`stage_requirements` (milestones, deliverable types, gate conditions, approver role)
`team_rules` · `role_library` (name, min/max holders, mandatory, rotation cadence)
`deliverable_types` · `certification_rules` · `badge_rules`
`organisers` · `integration_mappings` (Phase 2)

## N3. Enrolment, consent, orders
`enrolments` (student_id, programme_id, status, hold_expires_at)
`consent_templates` · `consent_template_versions` *(immutable)*
`consent_requests` (enrolment_id, signer_id, status, version_id, expires_at)
`consent_signatures` *(immutable — sig image ref, timestamp, IP, UA, version hash, event sequence JSONB)*
`consent_documents` *(immutable — OSS path, SHA-256)*
`orders` · `order_lines` *(immutable after issue)*
`payment_attempts` *(append-only)* · `receipts` *(immutable, gapless sequence)*
`refunds` *(append-only)* · `credit_notes` *(immutable)*
`enrolment_batches` · `batch_rows`

## N4. Teams
`teams` (programme_id, category, status, visibility, locked_at, approver_id)
`team_members` (team_id, student_id, status, joined_at, left_at)
`team_role_assignments` · `role_tenures` (start, end, completion status → badge trigger)
`team_deliverables` · `deliverable_submissions` · `deliverable_reviews`
`stage_gates` (team_id, stage, status, submitted_at, decided_at, decided_by, reason)

## N5. Team finance (record only)
`budgets` · `budget_lines` · `budget_categories`
`team_transactions` (type income/expense, amount, category, status, verified_by)
`transaction_receipts` (uploaded evidence)
`sponsorship_records` · `sponsorship_agreements` (uploaded)

## N6. Learning
`sessions` (type lesson|event, status, quota, waitlist policy, counts_toward_learn, location, **timezone**)
`session_versions` (session_id, old_datetime, new_datetime, reason — reschedule history)
`session_series` · `bookings` (status, waitlist position, promoted_at)
`attendance` (booking_id, status, recorded_by, recorded_at)
`mentors` (status: active|inactive|departed) · `mentor_availability`
`assessments` · `assessment_results`

## N7. Recognition
`avatars` (library) · `avatar_uploads` (moderation queue)
`badges` · `badge_awards` · `certificates` · `certificate_templates`
`portfolios` · `portfolio_exports`

## N7a. Communication
`announcements` (title, body, audience_type, publish_at, status)
`announcement_audiences` (announcement_id, scope: role|programme|school)
`message_threads` (context_type, context_id, participants) · `messages` (thread_id, sender_id, body, read_at)
Thread access is role-checked: a guardian may open a thread only to staff of their child's programmes.

## N8. Notifications
`notification_rules` · `notification_templates` (per event, channel, language)
`notification_preferences` (user × category × channel)
`notifications` · `delivery_log` (provider id, status, timestamps)
`reminder_ladders` · `reminder_state`

## N9. Approvals (shared engine)
`approval_requests` (entity_type, entity_id, approver_id, status, reason, decided_at)
`approval_escalations`

## N10. Dashboards & reporting
`dashboard_presets` (role, layout JSON) · `dashboard_layouts` (user, role, layout JSON)
`report_definitions` · `report_schedules` · `report_runs`
`aggregate_*` tables (trigger-maintained)

## N11. Audit & compliance
`audit_events` *(immutable, INSERT only — UPDATE and DELETE revoked from the app role)*
`data_access_log` · `consent_register` · `cross_border_transfers`
`data_subject_requests` · `retention_policies`
`reconciliation_log` (nightly assertion results)
`sync_jobs` · `sync_logs` (Phase 2)

## N12. Critical database constraints

| Constraint | Enforces |
|---|---|
| Unique partial index on `team_members` where status = active | One active team per student per programme |
| Sequence on `receipts.receipt_no` allocated in-transaction | Gapless, no duplicates |
| Check: enrolment cannot reach Active without a signed consent | Consent gate |
| Check: student must have ≥1 active guardian link to create an enrolment | Guardian requirement |
| Revoke UPDATE, DELETE on `audit_events`, `receipts`, `consent_signatures`, `consent_template_versions`, `credit_notes` | Immutability |
| Foreign key `order_lines` → snapshot values, not `fee_items` | Price history preserved |
| Unique on `pairing_codes.code` where used_at is null | Code collision |
| Partial unique index: one enrolment per (student, programme) in non-terminal status | Double-submit idempotency (E8) |
| `SELECT FOR UPDATE` on programme counter at enrolment insert | Last-seat race (E8) |
| Mentor cannot reach Departed with future sessions | Mentor lifecycle (C4) |

---

# PART O — TECH STACK

| Layer | Choice | Phase |
|---|---|---|
| Frontend | React 18 + TypeScript + Vite + Ant Design 5 + Ant Design Pro | 1 |
| Charts | Ant Design Charts (G2Plot); ECharts for dense views | 1 |
| Dashboard grid | react-grid-layout | 1 |
| Signature capture | react-signature-canvas (in-house) | 1 |
| Backend | Laravel 12, API-only (REST/JSON) | 1 |
| Database | PostgreSQL — ApsaraDB RDS (HK) | 1 |
| Queue | Redis + Laravel Horizon | 1 |
| Storage | Alibaba Cloud OSS (HK) | 1 |
| PDF generation | Server-side (receipts, consent documents, certificates) | 1 |
| Auth | Laravel Sanctum (session/JWT) | 1 |
| Identity federation | **Logto** | **Final sprint (S11), after UAT — never in initial sprints** |
| Payment gateway | **QFPay** | **2** |
| Organiser sync | Generic connector framework | 2 |
| Hosting | Alibaba Cloud ECS (HK), Docker Compose | 1 |
| Mobile shell | App-like responsive (bottom tabs, drawer, sheets, gestures) + PWA manifest — Design System §17 | 1 |

**All initial sprints use Laravel Sanctum.** Logto arrives only in the final sprint (S11), after UAT, behind the same auth interface, at which point it becomes both the identity provider for KA users and the OIDC provider external organisers federate against. Designing the auth layer behind an interface now means the Phase 2 swap does not touch application code.

**Why Laravel:** Horizon gives a visual queue dashboard with failed-job inspection and retry from the UI — the close-loop requirement that no async work fails silently is met out of the box. The failed jobs table is a dead-letter queue with nothing to build. Scheduler handles reminder ladders, hold-window expiry and nightly reconciliation. Eloquent plus PostgreSQL transactions give receipt-numbering integrity.

## O2. Production operations

**Shared upload service (all file intake — receipts, evidence, deliverables, logos, agreements):** per-context MIME allow-list (images jpg/png/webp · documents pdf · evidence pdf/jpg/png) · size caps (images 5 MB, documents 15 MB) · images re-encoded server-side, stripping payloads and EXIF · **ClamAV scan as a queued job — a file is quarantined and invisible until the scan passes**; a hit quarantines, alerts an admin and writes an audit event.

**Environments:** `local → staging → production`. Staging mirrors the production Docker Compose with its own RDS database and OSS bucket. Nothing reaches production without passing staging.

**Backup and restore:** RDS automated nightly snapshots retained 7 days plus weekly retained 4 weeks · OSS versioning enabled on the receipts and consent-documents buckets · a **restore drill before go-live and quarterly thereafter**, with results logged — a backup that has never been restored is a hope, not a backup.

**Migration gate:** every migration is reversible or explicitly flagged destructive and reviewed; CI runs `migrate --pretend` against a staging clone before any production deploy.

---

---

# PART P — CLOSE-LOOP ARCHITECTURE

## P1. Complete state machine register

| Workflow | States |
|---|---|
| Relationship | Requested → Pending → Active → Revoked / Expired / Superseded |
| Programme | Draft → Ready → Published → Enrolment Closed → Running → Completed → Archived |
| Enrolment | Intent → Consent Pending → Payment Pending → Active → Completed / Withdrawn / Suspended / Declined / Expired |
| Consent Request | Draft → Sent → Viewed → Signed / Declined / Expired → Superseded |
| Order | Draft → Issued → Awaiting Payment → Partially Paid → Paid → Overdue → Cancelled / Refunded / Partially Refunded / Voided |
| Payment Attempt | Initiated → Pending → Confirmed / Failed / Abandoned → Reversed |
| Refund | Requested → Under Review → Approved / Rejected → Processed / Failed → Withdrawn |
| Team Formation | Draft → Recruiting → Minimum Met → Submitted → Approved / Rejected → Locked → Active → Completed / Disbanded |
| Team Membership | Requested → Confirmed → Active → Left / Removed / Transferred |
| Role Tenure | Scheduled → Active → Completed → Badge Issued / Terminated Early |
| Stage Gate | Not Started → In Progress → Submitted → Under Review → Passed / Returned |
| Budget | Draft → Submitted → Under Review → Approved / Changes Requested → Active → Closed |
| Transaction | Draft → Receipt Attached → Submitted → Under Review → Approved / Rejected → Recorded → Verified |
| Deliverable | Draft → Submitted → Under Review → Feedback → Revised → Accepted → Scored |
| Booking | Requested → Confirmed / Waitlisted → Attended / No-show / Cancelled → Recorded |
| Session | Draft → Published → Full → In Progress → Completed / Cancelled / Rescheduled |
| Mentor | Active → Inactive → Departed |
| Assessment | Draft → Published → Open → Closed → Graded → Released |
| Avatar Upload | Pending → Approved / Rejected → Appealed → Final |
| Withdrawal | Requested → Under Review → Approved / Rejected → Refund Computed → Closed |
| Certificate | Criteria Met → Generated → Issued → Delivered → Verified → Revoked |
| Batch | Draft → Validating → Validated → Processing → Partially Complete → Complete / Closed with exceptions |
| Approval | Pending → Under Review → Approved / Returned → Escalated |
| Notification | Queued → Sent → Delivered / Bounced → Opened / Dead-lettered |
| Sync Job (Ph2) | Queued → In-flight → Success / Failed → Retry → Dead-lettered → Resolved |

## P2. Audit log

One immutable `audit_events` table. Columns: `event_id` · `occurred_at` · `actor_id` · `actor_role` · `on_behalf_of` · `entity_type` · `entity_id` · `from_state` · `to_state` · `action` · `reason` · `payload_before` · `payload_after` · `ip_address` · `user_agent` · `request_id` · `programme_id`.

UPDATE and DELETE revoked from the application role at the database. INSERT only. Data access logging, cross-border transfer records and consent history all derive from this one table.

## P3. Nightly reconciliation assertions

| Assertion | Fails when |
|---|---|
| `enrolled_count` == COUNT(active enrolments) | Cached counter drifted |
| Receipts total == confirmed payments total | Payment recorded without receipt |
| Order paid total == SUM(confirmed attempts) | Allocation error |
| Net position == receipts − credit notes | Refund not credited |
| Every active enrolment has a signed consent | **Compliance breach** |
| Team member count == COUNT(active memberships) | Drift |
| Budget actual == SUM(approved transactions) | Drift |
| Badge count == COUNT(completed role tenures) | Missed issuance |
| Attendance records == COUNT(attended bookings) | Drift |
| Certificates == COUNT(students meeting criteria) | Missed issuance |
| Stage gate `passed` still satisfies its conditions | **Regressed gate** |
| No enrolment in a non-terminal state past its deadline | Stuck workflow |
| Receipt sequence has no gaps | Numbering integrity |
| Every reminder ladder in an open state has `next_run_at` in the future (±1h) | Silently-dead ladder |
| No student with a non-terminal enrolment has zero active guardian links | **Guardian continuity (B8)** |
| No Published session past its end time without a terminal state | Dangling session |
| Every Withdrawn enrolment has a closed Withdrawal record with policy computation | Withdrawal loop (E7) |
| No duplicate non-terminal enrolment per (student, programme) | Idempotency (E8) |

Results write to `reconciliation_log`. Any mismatch raises an alert on the Academy Admin dashboard and fires `reconciliation.mismatch`.

## P4. No silent failure
Every external call is a queued job with explicit status, bounded retries and a dead-letter queue surfaced in the admin UI. Every scheduled job (hold expiry, reminder ladders, reconciliation, report delivery) logs a run record — a job that fails to run at all is itself an alert.

---

# PART Q — UPDATED SITEMAP DELTAS

The v3 sitemap stands, with these additions.

## Q1. Student
- **2.4 My Enrolments** → tabs become `Active | Awaiting Consent | Awaiting Payment | Past`, each row showing the status timeline (Part E5)
- **NEW 6.5 Payments & Receipts** under Finance → tabs `My Orders | Receipts | Payment History` (read-only when payer is the guardian)
- **7.4 Connections** → adds `Invite Guardian` with pairing-code generation

## Q2. Parent
- **NEW 3.x Consent** → tabs `To Sign | Signed | Declined | History` with document download
- **4. Payments** → adds `Pay Now` action, `Receipts` with PDF download and reprint

## Q3. Teacher
- **3.3 Approvals** → adds `Stage Gates` and `Refund Requests` tabs (one shared approval engine)

## Q4. School Administrator
- **NEW 3.4 Bulk Enrolment Batches** → tabs `Active | Complete | Exceptions` with the batch dashboard
- **6. Billing** → adds `Consolidated Invoices` and `Consent Status` tabs

## Q5. Academy Administrator
- **2.2 Programme Configuration** → becomes the hub-and-spoke wizard (Part D) with readiness indicator and pre-flight check
- **NEW 6.5 Orders** → tabs `All Orders | Awaiting Payment | Overdue | Record Offline Payment`
- **NEW 6.6 Receipts** → tabs `Issued | Reprints | Credit Notes | Sequence Audit`
- **NEW 6.7 AR** → tabs `Aging Summary | Aging Detail | By Customer | DSO | Statements | Collections`
- **NEW 6.8 AP** → tabs `Aging Summary | Aging Detail | Vendors | Commitments`
- **NEW 8.4 Consent Management** → tabs `Templates | Versions | Requests | Signatures | Re-consent`
- **NEW 9.6 Notification Rules** → tabs `Event Catalogue | Templates | Reminder Ladders | Preference Defaults | Delivery Log`
- **NEW 12.9 Dashboard Presets** → per-role default layouts and permitted widget sets

---

# PART R — OPEN DECISIONS

| # | Decision | Blocks | Recommendation |
|---|---|---|---|
| 1 | **Charity component is not modelled.** It is one of the four Summer Program pillars. | Programme config, Pitch stage | Reframe Pitch to cover sponsorship **and** philanthropy via a `project_type` field, rather than building a separate module |
| 2 | **Invitation-only onboarding** — should self-registration be removed entirely? | Onboarding, guardian linking, Part B4 | Yes. Guardian registers first by academy invitation, then creates/links the student. Better fit for the consent model and the client-confidentiality posture. |
| 3 | **Does the platform also serve first-generation members** (Members Only Events)? | Role model | If yes, add a sixth **Member** role now — events, RSVP, directory only. Cheap now, awkward later. |
| 4 | **Multi-jurisdiction scope** — eight territories, not just HK and UK | Data residency, localisation, currency | Add timezone and jurisdiction fields in Phase 1 schema; localisation and multi-currency in Phase 2 |
| 5 | Brand assets — logo vector in colour, exact hex values, typeface licences, guidelines | Theming lock | Request from client. Site publishes only a monochrome logo and no palette. |
| 6 | Is Learn assessed per student or per team? | Gate logic, certification | Per student for certification; per team for the gate, passing when a configurable percentage qualify |
| 7 | What are "School Team" and "Armour Team"? | Team formation config | Affiliation categories — School (via partner school) vs Armour (direct). Confirm whether more are needed. |
| 8 | Consent required from all guardians or any one? | Consent engine | Any one by default, `consent_requires_all_guardians` flag per programme |
| 9 | Hold window length | Enrolment expiry | 7 days default, configurable per programme |
| 10 | Segregation of duty on offline payments — required or optional? | Order module | Optional per programme; recommended for amounts above a configurable threshold |
| 11 | HKUST co-powered certification terms | Certification config | Need attendance threshold, assessment criteria, signatories, logo usage rights |
| 12 | Custom avatar upload in Phase 1? | Moderation workload | Library only in Phase 1 |
| 13 | Rotation cadence default | Rotation engine | Per stage, not weekly |
| 14 | Mainland China entity | ICP, WeChat, CDN | Still outstanding |
| 15 | Consent wording legal review | Launch | Hong Kong-qualified lawyer to review before go-live |

## Phase 1 scope

Onboarding and consent · guardian linking with pairing codes · programme catalogue and setup wizard · enrolment with consent gate · order module with offline payment and receipts · AR reporting · team formation · Activity Tracker with all five stages (Design as a mock launcher) · team roles and rotation · team finance recording · Lessons and Events with quota, waitlist and attendance · bulk enrolment · role dashboards with presets · standard reports · notification engine · profile, avatar library, badges · admin configuration surface · audit log and nightly reconciliation

## Phase 2

Logto identity federation · QFPay gateway · external organiser sync and live Design-stage progress · certificate issuance with co-branding · verifiable badge credentials · custom avatar upload with moderation · WhatsApp and WeChat channels · custom report builder · AP module · dashboard drag-and-drop personalisation · mobile app

---

*End of document.*

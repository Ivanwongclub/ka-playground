# SPRINT KAP-S06B — Public registration requests (OD-23)

> New card, written 2026-07-24 on OD-23 confirmation. NOT started; not scheduled until Leo
> confirms the slot (proposed: after S06, before S07 — rationale in §PLACEMENT below).
> OD-24 (second-guardian governance) rides here IF confirmed before card start; otherwise
> it stays open and this card ships without touching pairing flows.

## GOAL
The platform's **first and only unauthenticated write**: a school-routed registration
REQUEST that creates no account, grants no session, and reads nothing — approval issues
the standard S01 guardian invitation. Ship it without weakening fail-closed RLS by one
degree: the anonymous surface can insert into exactly one table, blind, and nothing else.

## PLACEMENT (proposed)
After S06: every portal role is live by then (Members included, OD-22), school admins are
active daily users who will actually work the queue, and the flow is in production shape
before S09 wires its notification ladder and S10 pen-tests the anonymous surface. Nothing
in S04–S06 depends on it; accounts remain invitation-creatable throughout.

## IMPLEMENTS  OD-23 · FR068 · SR001 (as amended) · AMENDMENTS 2.28 · (OD-24 if confirmed)

## THE ANONYMOUS-WRITE DESIGN (Leo's four questions, answered before the card)

**1. Policy shape — insert-only for anonymous, no read at all.**
A new scope-context value `public`, set ONLY by a dedicated middleware bound to the
registration route group — never derived from a user, carrying NO actor_id, NO role, NO
capabilities, NO school/student ids. `registration_requests` is **scoped**:
- INSERT: `WITH CHECK (context = 'public' OR system)` — the string `'public'` appears in
  **exactly one policy clause platform-wide**, this one. A structural reconciliation
  assertion scans `pg_policies` and FAILS if `public` is referenced anywhere else, or in
  any SELECT/UPDATE/DELETE anywhere. Fail-closed elsewhere is untouched: an anonymous
  session still reads zero rows from every table — including this one.
- SELECT: system · school_admin of the ROUTED school (`school_id = ANY(app.school_ids)`) ·
  academy operations/audit_read/super. The requester cannot read their own row: the
  endpoint inserts **blind** (no `INSERT..RETURNING` — S02A established RETURNING checks
  SELECT policies) and returns a server-generated opaque reference without reading back.
- UPDATE (decision only): school_admin of the routed school · ops · system. DELETE: system.
- No status endpoint exists. The only externally observable state change is the
  invitation email arriving after approval.

**2. Enumeration resistance.**
The anonymous handler performs NO reads of scoped tables — validation is purely syntactic
(required fields, email format, length caps), so there is nothing to time-attack and no
lookup whose outcome could shape the response. Constant-shape response: the same
202 + opaque reference whether or not the email/student/guardian exists, whether or not a
duplicate request exists (dedupe happens at review, visible only to the reviewer —
refusing duplicates is an "already requested" oracle). School selection comes from the
opt-in public list only; the "my school isn't listed" free-text path routes to academy ops
with the identical response — no school-existence oracle. This matches the
`LinkController::requestByEmail` precedent: identical response regardless of account
existence.

**3. Rate limiting and abuse handling (first surface to need it).**
- Named limiter `throttle:public-registration`: **3/hour/IP + 10/day/IP** (2.13 family),
  429 beyond.
- **Honeypot field + minimum-fill-time check**: failing either silently drops the write
  but still returns the standard 202 — bots get no oracle.
- **Text only, hard length caps, NO uploads** — BI-10's pipeline never meets anonymity.
- **Per-school daily cap** (default 50): beyond it, submissions still 202 but land
  `Flagged`, the school's queue shows a flood banner, ops is alerted. Abuse burdens a
  review queue; it can never create accounts, sessions or reads.
- Every submission writes an audit event with **actor NULL** (S00 actor_role-NULL
  precedent), capturing IP + UA; school-admin side gets bulk-decline tooling.
- Unactioned requests expire (90d) via the retention machinery (2.16).

**4. Is the school list safe to expose publicly? NO — not by default.**
The full partner roster is the same commercially confidential relationship data that made
`team_categories` scoped (S02B ruling). Mechanism: `schools.public_listing` boolean,
**default FALSE**, settable by configuration admins, every change audited. The public
endpoint exposes ONLY opted-in schools' display names + an opaque slug — no ids, no
counts, no contacts, no completeness claim. The free-text fallback keeps unlisted schools
reachable without disclosing them. **Which partners opt in is a client decision per
school** — the mechanism ships; listing choices are data (client question logged).

## SCOPE CLASSIFICATION PLAN
| Table | Classification | Read set / justification |
|---|---|---|
| `registration_requests` | **scoped** | Pre-account personal data about a guardian and a named child. Read: system · school_admin of routed school · ops/audit_read/super. INSERT: `public` context (sole platform-wide occurrence, structural assertion) · system. UPDATE: reviewer set. No public read, ever — including the requester |
| `schools.public_listing` | column on existing global table | Opt-in flag, default OFF; the PUBLIC list endpoint exposes only opted-in display names — narrower than the authenticated global read |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Table + `public` context + policies + structural assertion**: migration ships table,
   policies, context middleware; `scope.public_context_confinement` assertion registered
   (pg_policies scan); anonymous-read probes across every table stay zero/denied.
2. **Public page + throttles + abuse handling**: trilingual `Register Interest` page
   (2.28 Q0); limiter, honeypot, fill-time, caps; blind insert + opaque reference;
   constant-shape probes pasted (existing vs non-existing email, listed vs free-text
   school, duplicate submit).
3. **Review queue + decision flow**: School Administrator `Registration Requests` tab
   (2.28 Q4) + ops cross-school queue; approve → standard S01 invitation (audited,
   identified approver); decline requires reason (audited); flood banner; bulk decline.
4. **Assertions + audit element**: `--tag=S06B` assertions (below); Registration Funnel
   audit element; five-branch pastes; AUDIT.md.

## NON-SCOPE
Account creation of any kind (approval issues an invitation, full stop) · notification
ladder (S09 — fire events) · CAPTCHA integration (revisit at S10 if abuse data warrants) ·
OD-24 pairing changes unless confirmed before start · Member anything.

## KEY VERIFICATIONS
- Anonymous session: INSERT succeeds blind; SELECT on registration_requests AND a sweep of
  every scoped table returns zero/denied; `INSERT..RETURNING` refused. Paste.
- `scope.public_context_confinement` deliberate failure: add a dummy policy referencing
  `public` on another table in a test schema → assertion goes red. Paste.
- Enumeration probes: byte-identical responses across existing/non-existing email,
  duplicate submit, listed/unlisted school. Paste.
- Throttle: 4th request in the hour → 429; honeypot fill → 202 but no row. Paste both.
- Five-branch on registration_requests: routed school_admin sees · other-school admin
  zero · guardian zero · student zero · Member zero (+ anonymous zero). Paste.
- Approve → invitation issued by identified actor, audited chain request→invitation.
  Decline without reason refused. Paste.
- Public school list: only `public_listing = true` rows; flag flip audited. Paste.

## AUDIT ELEMENT
**Registration Funnel Report** — requests by school / status / aging; approval-to-
invitation-acceptance conversion; flood/flagged history. School admin sees own school;
cross-school behind audit_read.

## ASSERTIONS (--tag=S06B)
- `scope.public_context_confinement`: `public` appears in exactly one INSERT policy on
  exactly one table, and in no read/update/delete policy anywhere.
- `registration.request_not_account`: no approved request without a linked invitation
  issued by an identified school_admin/ops actor; no account exists whose origin is a
  registration request except through an accepted invitation.
- `registration.queue_liveness`: no Pending request older than the expiry window.

## EXIT GATE
Tests + `--tag=S06B` + `--tag=S02A` green + all pastes above + bundle budget green +
AUDIT.md, gate commit.

# BUILD-PLAN.md — sprint sequence changes

The workflow review changes the shape of S04 onward and adds three cards. These are
INSTRUCTIONS for updating the plan Claude Code maintains — apply against the current file.

## The revised sprint sequence (Phase 1)

| Sprint | Scope | Change from current plan |
|---|---|---|
| S00–S03 | Foundation · Identity · Access/RLS · Programme config · Consent | unchanged (built / in build) |
| **S04A** | Enrolment states, awaiting-a-team pool, formation deadline, withdrawal workflow, per-programme independence, scheduled-job auditing | **REWRITE** — team-based capacity, not individual seats. Consent-before-programme. Deadline jobs. (OD-25,27,28,37,57,58,59) |
| **S04B** | Orders, receipts, credit notes, refunds, offline recording, payment link, **MockProvider behind PaymentProvider interface** | **REWRITE** — the trigger (成團) lives in S05; S04B builds the machinery with the trigger out of scope. BI-9 scoped to manual. (OD-37,38,40,41,42,47,48) |
| **S05** | Teams, lobbies, formation, approval routing, 成團 → seat allocation → payment trigger, size waivers, post-成團 control, teacher-team link, five stages, rotating roles | **REWRITE** — wires 成團 to S04B's machinery. This is where capacity is claimed and payment fires. (OD-26,29,31–36,51,52,55,56) |
| **S06** | Sessions, attendance, assessment, member events | unchanged in scope |
| **NEW: S06-BATCH** | School bulk import — batch wizard, config-driven Excel template, record+invitation creation, draft teams, two payment modes, school-settled receivable, consolidated invoicing, batch failure path | **NEW CARD** — depends on S04B (payments) + S05 (teams). (OD-44–50) |
| **NEW: S-SELFREG** | School-routed self-registration request surface | **NEW CARD** — first unauthenticated write surface; depends on S01. Can land any time after S01; recommend alongside S06-BATCH since both touch school-admin approval. (OD-23) |
| S07 | Team finance | unchanged |
| S08 | Recognition | unchanged |
| S09 | Notifications — delivers the OD-60 catalogue raised by earlier sprints | unchanged in scope; note the 21-event catalogue is designed now |
| S10 | Go-live readiness | **add:** QFPay merchant-application status is a launch gate; credential rotation; PDF/A decision |
| **NEW: S-QFPAY** | QFPay adapter implementing PaymentProvider — hosted session, webhook signature verification, idempotency, settlement reconciliation assertion, async refunds | **NEW CARD** — Phase 2, pre-production. Gated by the merchant application (IN PROGRESS). Slots before S10 go-live once the account + API docs exist. (OD-40,43) |

## Parallel client workstream — put on the plan explicitly

**QFPay merchant application (IN PROGRESS).** Launch depends on it. It is a compliance process
outside the build's control — track it as a first-class dependency with its own status, not an
S10 discovery. When approved, confirm Alipay CN is enabled and settlement is HKD.

## Cross-cutting build notes to carry into every affected card

- Every scheduled job (deadline expiry, grace lapse, batch aging, 90-day auto-refund backstop)
  writes an audit event with a **system actor** (OD-58).
- Every new table is **classified in the scope map in its creating migration** (S02A discipline).
- Every module **raises its notification events** even though S09 delivers them (OD-60).
- The **awaiting-a-team pool** is one concept — do not build a separate waitlist (OD-28).

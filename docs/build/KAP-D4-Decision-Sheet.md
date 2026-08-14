# KA Playground — D-4 Decision Sheet (five open product questions)

> Five product decisions the journey audit surfaced that hadn't been ruled. Each has my **recommended ruling** + rationale + what it costs. Format matches the earlier answer rounds — **edit any cell you'd decide differently**; these are recommendations, not commitments. Verified against the live schema where it matters (notably: `credit_notes` already exists and is immutable, which shapes #2).

---

## 1 · Merge / de-duplicate records (audit O5)

**The problem:** three entry paths create people — family self-registration, school batch upload, and ops registration approval — so two records for one child are inevitable. Nothing today can merge them.

**Recommended ruling:** **Ops-only, request-then-apply, fully audited — never a silent merge.**
- Only academy ops (operations capability) may merge; school/family/mentor cannot.
- A merge is a **staged request** (like `team_change_requests`): pick surviving record + merged record, preview *every* row that will re-parent (enrolments, links, consents, orders), confirm with a reason → server applies under `system`.
- **The survivor keeps the verified guardian/school links;** the merged record's links are re-pointed, never dropped. If the two records have *conflicting* active guardian links, the merge **blocks** and routes to manual review (a child-safety edge — never auto-resolve whose guardian wins).
- Every merge writes a full `audit_events` row (before/after both records). Consents are **never** silently transferred — a merged-away consent that doesn't match the survivor's enrolment is re-issued, not moved.

**Why:** merge touches the child-safety backbone (guardian links) and the financial ledger (orders), so it's the highest-trust ops action after money. Request-then-apply + block-on-link-conflict keeps it from ever silently mis-attaching a child to the wrong family.
**Cost:** net-new `merge_requests` table + the re-parent engine. **v1? → No — v2.** It's real but not launch-blocking; until it ships, ops de-dupes manually (rare at launch volume). *Ruling: build the request grammar in v2; for v1, a manual ops runbook.*

---

## 2 · Fee adjustments / waivers / scholarships (audit F3/F4)

**The problem:** scholarships, sibling discounts, hardship waivers are routine in youth academies; there's no grammar for them, and editing an invoice would break the immutable ledger.

**Recommended ruling:** **Reuse the existing `credit_notes` grammar — reasoned, four-eyes, never edit an invoice.**
- Verified: **`credit_notes` already exists and is INSERT-only/immutable (BI-5).** So a waiver/scholarship/discount is a **credit note against the order** with a reason code — *not* a new table and *not* an invoice edit.
- Add a **reason taxonomy** (scholarship / sibling-discount / hardship-waiver / goodwill / correction) as an enum, so finance reporting can distinguish a scholarship from an error correction.
- **Four-eyes applies** — a credit note that reduces what a family owes is a money movement: recorder ≠ approver, same as payment confirm. A large waiver may warrant a higher approval threshold (config later).
- Family sees the *net* owed (order − credit notes) on the Payments surface, with the credit line itemized ("Scholarship −HK$1,800"); they never see another family's credit.

**Why:** the immutable-ledger + four-eyes machinery already exists; waivers ride it rather than inventing a parallel path that could bypass financial integrity.
**Cost:** small — a reason enum + a "create credit note" ceremony on the finance side + the net-owed display on the family side. **v1? → Yes, minimal** (the enum + finance ceremony; the reason taxonomy can start short). *Ruling: in v1, reusing credit_notes.*

---

## 3 · Month-end close / period lock (audit F5)

**The problem:** a period that can be silently edited after close isn't a ledger.

**Recommended ruling:** **Yes — a soft close with a reason-gated reopen.**
- A finance officer (or super) **closes a period**; after close, no new payments/credit-notes/refunds may post to it.
- **Reopen is possible but reason-gated + audited** (soft, not hard, close) — real academies find a stray item after close; a hard lock forces ugly workarounds. The audit trail on reopen is the control.
- Closing runs the reconciliation assertions first and **refuses to close on a red** (unbalanced books can't be sealed).
- A closed period is visually unmistakable (locked chip) on every finance surface touching it.

**Why:** accounting hygiene and audit-defensibility; the reconciliation-gate-before-close ties it to the existing `reconciliation_log`.
**Cost:** a `period_locks` table + the post-date guard on money writes + the close/reopen ceremonies. **v1? → v1 the *lock primitive*, v2 the fuller month-end tooling.** *Ruling: v1 gets close/reopen + the post-date guard; period reports are fast-follow.*

---

## 4 · Grading rubric display + moderation / second-marker (audit M5)

Two separable questions:

**4a · Rubric display — Recommended ruling: Yes, v1.**
- A grader grading against an invisible rubric is guesswork. Show the assessment's **criteria/rubric alongside the score field**. Read-only reference during grading.
- **Cost:** the assessment needs rubric/criteria storage (net-new — verified no rubric table exists). Small if rubric is structured text per assessment.

**4b · Moderation / second-marker — Recommended ruling: schema-ready in v1, surface in v2.**
- A second-marker/moderation step (a score reviewed before it can be released) is genuinely valuable but adds a whole workflow. **Make the data model able to carry it** (a nullable moderator + moderation state on the result) so v2 can turn it on without a migration that touches released rows — but **don't build the UI in v1.**
- Release stays academy-ops-only regardless (unchanged immovable-adjacent).
- **Cost (v1):** just the nullable columns. **v2:** the moderation queue + ceremony.

**Why:** rubric display is cheap and materially improves grading quality; moderation is a v2 workflow but cheap to leave a seam for now.
*Ruling: 4a in v1; 4b schema-seam in v1, UI in v2.*

---

## 5 · School term report / export (audit SC5)

**The problem:** schools answer to their own management and will ask for participation/attendance/outcome reports in week one.

**Recommended ruling:** **Yes — a CSV export of the school's own *already-released* data only. No new read surface.**
- The school exports participation + attendance + **released** results for **its own roll** (`app.school_ids` scope) — a straight projection of reads it already has, serialized to CSV.
- **Critically: it exposes nothing new.** No unreleased results (embargo holds in the export exactly as on screen), no other school's students, no guardian PII beyond what the school's reads already return. The export is entitlement-shaped like every screen (an export is just a read).
- **Not** a report *builder* in v1 — one well-chosen CSV (or a couple of fixed formats) beats a query tool. A richer reporting module is v2 if demanded.

**Why:** it's the cheapest way to satisfy a real weekly need, and because it's a serialization of existing entitled reads, it introduces **zero** new visibility risk — the export must run through the same RLS the screens do (never a privileged batch read).
**Cost:** small — a scoped CSV endpoint honoring RLS. **v1? → Yes** (fixed-format CSV of released data). *Ruling: in v1.*

---

## Summary of rulings (for the backlog)

| # | Question | Ruling | v1? | New schema |
|---|---|---|---|---|
| 1 | Merge / de-dupe | Ops-only, request-then-apply, block on guardian-link conflict, audited | **v2** (manual runbook for v1) | `merge_requests` + re-parent engine |
| 2 | Fee waivers / scholarships | Reuse immutable `credit_notes` + reason enum + four-eyes; family sees net owed | **v1 (minimal)** | reason enum only (table exists) |
| 3 | Period lock | Soft close, reason-gated reopen, refuse-close-on-red-reconciliation | **v1 (primitive)** | `period_locks` + post-date guard |
| 4a | Rubric display | Show criteria beside score, read-only | **v1** | rubric/criteria storage |
| 4b | Moderation / 2nd marker | Schema seam now, UI later | **seam v1, UI v2** | nullable moderator + state columns |
| 5 | School term report | CSV export of own *released* data, RLS-scoped, no new reads | **v1** | scoped CSV endpoint (no table) |

**Every ruling preserves an immovable:** merge blocks on guardian-link conflict (child safety); waivers ride the immutable ledger + four-eyes (financial integrity); the school export honors the embargo and cross-school isolation (an export is a read). None introduces a new visibility path.

*Override any cell and I'll re-fold it into the consolidated build backlog.*

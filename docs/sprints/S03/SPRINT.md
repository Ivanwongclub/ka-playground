# SPRINT KAP-S03 — Consent engine (in-house e-sign)

> Adjusted 2026-07-25 per the card-adjustment mechanism: OPEN-DECISIONS follow-ons (OD-10,
> OD-20, R15), S02A/S02B AUDIT §5 carry-forwards (classification review explicit at S03;
> guardian-reads-terms doctrine from S02B step 3), and Leo's four pre-adjustment items
> (language-scoped versions, placeholder discipline, classification plan with pre-stated
> read sets, five-branch isolation per scoped table). Not committed; not started.

## GOAL
Legally defensible in-house e-sign: **language-scoped** versioned templates (OD-20), hash-bound
signatures (BI-6 — the hash matches the version IN THE LANGUAGE IT WAS SIGNED IN), evidence
bundles. Every table classified before build; signature data is personal data about minors'
guardians and is scoped accordingly.

## PRECONDITIONS
- [x] S02B gate PASSED (`cfcfbfa`) · S02A scope layer + coverage assertion live
- [x] OD-10 (any one guardian by default, per-programme `consent_requires_all_guardians`) ·
  OD-20 (language-scoped versions) · R15 (placeholder wording path) — all decided

## IMPLEMENTS  BI-6 · OD-10 · OD-20 · R15 · Spec Part G · FR035–FR038

## SCOPE CLASSIFICATION PLAN (declared before work starts)
**Recall: `global` = readable by EVERY authenticated session (students, guardians, Members,
teachers). Tie-breaker: when in doubt, scoped. Read sets for personal data are STATED HERE,
before building (Leo item 3), as S02B step 3 did.**

| Table | Classification | Read set / justification |
|---|---|---|
| `consent_templates` | global | Catalogue metadata only (name, programme binding, status) — no legal text, no personal data; readable by every authenticated session; writes configuration.manage |
| `consent_template_versions` | **scoped** | The legal text + its SHA-256, one row PER LANGUAGE (OD-20), immutable once published (INSERT-only trigger, BI-1 pattern). Read: system · academy staff · **bound parties (guardian/student/school_admin) for PUBLISHED programmes' selected templates** — the S02B doctrine: you may read what you can be bound by, in every language version; drafts academy-only; Members nothing. Write: configuration.manage; publish freezes |
| `consent_requests` | **scoped** | Who must sign for which student/programme — personal. Read: system · academy ops/audit · the addressed signer (guardian) · the student it concerns (status visibility, E5) · school_admin of the student's school (outstanding-consent chasing, H4 — status, via this table, never signature evidence). Write: system (S04A creates per enrolment; until then, fixture/port creation) |
| `consent_signatures` | **scoped — the strictest table on the platform so far** | Signature image ref, stroke data, IP, UA, event sequence: personal data about a minor's guardian. Read: system · **the signer alone** among portal roles · academy operations/audit_read/super (compliance duty). NOT the student, NOT co-guardians, NOT school admins, NOT teachers — they get STATUS from consent_requests, never evidence. Write: INSERT only by the signing flow (signer's own context or its allowlisted elevation); immutable at the DB (trigger + revoke, BI-6/N) |
| `consent_documents` | **scoped** | Signed PDF + hash. Same read set as consent_signatures, plus the signer downloads their own signed copy. Files ride the S00 upload service (BI-10) — the uploads table is already scoped |

Every migration ships classification + policies with the table; each step's VERIFY pastes
`reconcile:run --tag=S02A` green. If any classification proves wrong in implementation: STOP,
amend the plan.

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Templates + language-scoped versions (OD-20)**: rich text + HTML source modes, merge fields,
   signature anchors (publish blocked without `{{signature}}`). **Each language (EN/TC/SC) is its
   own version row with its own SHA-256** — never a translation of another's hash. A template
   cannot be selected by a publishing programme unless ALL THREE language versions exist
   (pre-flight error joins the S02B check; assertion below). **R15 placeholder wording ships in
   all three languages, flagged NON-LEGAL AND NON-BINDING in the template body itself AND in the
   admin UI** (banner per §12.3), each language version carrying its own hash; the S10 readiness
   check that no live version still carries placeholder text is registered as a named check now,
   enforced at S10.
2. **Signing flow (FR036)**: authenticated guardian session · scroll-to-end gate · affirmation
   always required · drawn (PNG + stroke vectors) / typed capture · full evidence capture (server
   NTP timestamp, IP, UA, version id + hash IN THE SIGNED LANGUAGE, event sequence) ·
   per-programme `consent_requires_all_guardians` honoured (OD-10; default any-one). The signing
   UI renders the version in the signer's chosen language and RECORDS which language was signed.
3. **Signed-PDF generation + audit certificate page (FR038)**; storage via the S00 upload service
   (BI-10); document hash recorded; evidence bundle export.
4. **Versioning / re-consent / decline (FR037)**: material vs non-material changes; material →
   supersede + fresh requests; decline reaches a terminal state, audits the reason, releases
   fast (E4 semantics arrive with S04A; the state machine ships now). Re-consent is
   LANGUAGE-AWARE (OD-20a, confirmed): a material change to the TC version supersedes TC
   signatures; an unchanged EN version's signatures stand — verify explicitly. **AND (OD-20a
   addition, Leo): a material change must be applied to ALL THREE languages together — version
   parity across languages is a PUBLISH condition. A material change to one language blocks
   publish (pre-flight ERROR) until all three are updated; two live versions saying materially
   different things, both valid, both signed, is worse than either problem alone.**

## NON-SCOPE
Enrolment preconditions (S04A wires "consent required before Active") · notification ladders
(S09; fire events, don't build channels) · lawyer-approved wording (R15: before go-live, S10
gate) · Member anything (OD-22).

## KEY VERIFICATIONS
- Per step: `reconcile:run --tag=S02A` green after migrations (classification shipped with table).
- **Five-branch live isolation PER SCOPED TABLE (Leo item 4 — policy exists ≠ policy correct):**
  - `consent_template_versions`: academy staff sees drafts+published · guardian/student/
    school_admin see published-programme versions in all three languages · Member zero. Paste.
  - `consent_requests`: addressed guardian sees theirs · the student sees status · school_admin
    of the school sees outstanding rows · other-school admin zero · Member zero. Paste.
  - `consent_signatures`: signer sees their own · co-guardian of the SAME student sees zero ·
    school_admin zero · ops/audit-capability admin sees (compliance) · Member zero. Paste — the
    co-guardian-zero row is the one that proves the strictest read set.
  - `consent_documents`: signer downloads their own bundle; everyone else per signatures. Paste.
- **BI-6 language-scoped (OD-20)**: sign the SC version → stored hash equals the SC version's
  SHA-256 and NOT the EN version's (paste both hashes); tampered fixture fails (paste).
- Programme publish with a language version missing → pre-flight ERROR (paste).
- **Language drift (OD-20a): one language materially updated, the other two not → pre-flight
  ERROR blocks publish until parity; paste the error and the post-parity pass.**
- Placeholder flags visible in template body AND admin UI in all three languages (screenshot).
- Scroll-to-end + affirmation + stroke: three independent gates, each with its own unmet-state
  refusal (§16 signature pad; paste the three refusals).
- Material TC change → TC signatures superseded + fresh requests; EN signatures untouched (paste).
- Decline → terminal state + audited reason (paste).
- Evidence bundle export from seed: PDF + hash + audit trail, inspected (gate).

## AUDIT ELEMENT
**Consent Evidence Report** — signature coverage by template version AND LANGUAGE; outstanding /
declined / expired lists; full evidence bundle export (PDF + hash + audit trail) per signature —
the bundle a legal challenge would demand. Behind audit_read.

## ASSERTIONS (--tag=S03)
- **BI-6**: every Signed request's stored hash equals its template version's SHA-256 — matched to
  the version row in the LANGUAGE SIGNED (OD-20).
- **Language completeness**: no published programme's selected template lacks any of the three
  language versions.
- No active signature on a superseded version without an open re-consent request (fully active
  from S04A; ships now, vacuous-aware like links.guardian_coverage).
- (R15 placeholder-gone check is registered as a NAMED S10 readiness item, not a nightly
  assertion — placeholder text is EXPECTED during build and a permanently-red assertion trains
  people to ignore red.)

## EXIT GATE
Tests + `reconcile:run --tag=S03` green + `--tag=S02A` green + one evidence bundle exported from
seed and inspected + the five-branch pastes for all four scoped tables + bundle budget green.
AUDIT.md (carry the R15/S10 named check + client-question status forward), gate commit.

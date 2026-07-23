# SPRINT KAP-S03 — Consent engine (in-house e-sign)

## GOAL
Legally defensible in-house e-sign: versioned templates, hash-bound signatures, evidence bundles.

## PRECONDITIONS  S02 gate PASSED.

## IMPLEMENTS  BI-6 · Spec consent sections

## SCOPE
1. Templates (rich text + HTML source) · versions with SHA-256 · merge fields · signature anchors.
2. Signing flow: scroll-to-end, affirmation, drawn/typed capture.
3. Signed-PDF generation + audit certificate page; storage via the S00 upload service (BI-10).
4. Versioning / re-consent flow; decline path.

## NON-SCOPE
Enrolment preconditions (S04A wires "consent required before Active") · notification ladders (S09;
fire events, don't build channels).

## KEY VERIFICATIONS
- Signed request's document hash == template version hash (BI-6); a tampered fixture fails (paste).
- New template version → open re-consent requests created for affected active signatures.
- Decline path reaches a terminal state and audits the reason.

## AUDIT ELEMENT
**Consent Evidence Report** — signature coverage by template version; outstanding/declined/expired
lists; full evidence bundle export (PDF + hash + audit trail) per signature — the bundle a legal
challenge would demand.

## ASSERTIONS (--tag=S03)
Every Signed request has a document with matching hash · no active enrolment on a superseded version
without an open re-consent request (fully active from S04A).

## EXIT GATE  Tests + tag green + one evidence bundle exported from seed and inspected. AUDIT.md, gate commit.

# CLAUDE.md — surgical edits (do NOT replace the whole file)

Three precise changes. Each shows the exact current line and its replacement.
Apply with a text editor or a scripted find-replace. Nothing else in CLAUDE.md changes.

---

## EDIT 1 — §1 "Payments" row (line ~20)

The current row says QFPay is out of scope and not to be scaffolded. That is now false —
a MockProvider drives the Phase-1 flow behind a provider interface.

**FIND:**
```
| Payments | Offline recording only. QFPay is Phase 2 — do not scaffold for it |
```
**REPLACE WITH:**
```
| Payments | Manual recording (school-settled) + a MockProvider behind a PaymentProvider interface. QFPay is Phase 2, pre-production, gated by the merchant application — build the interface now, the QFPay adapter later. HKD only; the gateway handles Alipay CN and settles HKD |
```

---

## EDIT 2 — §3 BI-9 (line ~62)

Segregation of duty must narrow to manually recorded payments, since gateway/mock payments
confirm themselves and have no human recorder.

**FIND:**
```
| BI-9 | Segregation of duty: recorder ≠ confirmer on payments **and refunds**. Enforced server-side, not by UI hiding | Sprint 4 + 2.17 |
```
**REPLACE WITH:**
```
| BI-9 | Segregation of duty: recorder ≠ confirmer on **manually recorded** payments and refunds (school-settled, offline). Enforced server-side, not by UI hiding. Gateway and mock payments confirm themselves and are out of BI-9 scope (OD-41) | Sprint 4 + 2.17 + OD-41 |
```

---

## EDIT 3 — §7 onboarding line (line ~124)

Invitation-only is no longer absolute: a school-routed self-registration REQUEST exists (OD-23),
and bulk import issues guardian invitations. The request creates no account.

**FIND:**
```
- **Invitation-only onboarding.** There is no public sign-up, ever — including after Logto.
```
**REPLACE WITH:**
```
- **Invitation-led onboarding.** No self-service account creation. The only public surface is a school-routed self-registration REQUEST (OD-23), which creates no account — a school administrator approves it against their own roll, and that approval issues the standard guardian invitation. Bulk import likewise issues guardian invitations, never parent accounts (OD-44). Students never self-create accounts; a guardian always anchors them.
```

---

## EDIT 4 — §1 add a supersession note (after the canonical-documents list, ~line 30)

So the invitation-only reversal is declared, not discovered.

**FIND:**
```
- `docs/OPEN-DECISIONS.md` — undecided items. **If your current step depends on one, STOP**
```
**REPLACE WITH:**
```
- `docs/OPEN-DECISIONS.md` — undecided items. **If your current step depends on one, STOP**

**Known supersessions (spec/amendment overridden by a later decision):**
- Spec L4 / B10 "invitation-only, no public sign-up" is superseded by **OD-23** (school-routed self-registration request) and **OD-44** (bulk import issues guardian invitations). Neither creates an account without approval.
- The individual enrolment waitlist (Spec E) is superseded by **OD-28** (awaiting-a-team pool) under team-based capacity (**OD-25**).
- "QFPay — do not scaffold" is superseded by **OD-40** (MockProvider behind a PaymentProvider interface in Phase 1).
```

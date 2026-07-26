# Workflow review → repo changes (2026-07-25)

Four files. None is a full replacement — all are **append blocks or surgical edits**, because the
live repo on your machine has OD-1..OD-24 and build-state updates this sandbox copy does not.
Applying a full-file overwrite would clobber Claude Code's committed work. Do it as below.

## What to do with each

| File | Action | Target in repo |
|---|---|---|
| **OD-APPEND.md** | APPEND its table rows to the existing table | `docs/OPEN-DECISIONS.md` |
| **CLAUDE-EDITS.md** | Apply 4 find-replace edits | `CLAUDE.md` |
| **REGISTER-EDITS.md** | Apply 3 edits (amend SR001, append FRs, add changelog row) | `docs/requirements/REGISTER.md` |
| **BUILD-PLAN-EDITS.md** | Update the sprint table + add 3 new cards' rows | `docs/BUILD-PLAN.md` |

## Recommended way to apply

Hand these four files to **Claude Code** in the build session with:

> Apply the four handoff files in docs/handoff/ to the repo:
> OD-APPEND.md appends to OPEN-DECISIONS.md; the three *-EDITS.md files are surgical
> find-replace edits to CLAUDE.md, REGISTER.md and BUILD-PLAN.md. Before applying, check each
> OD number in OD-APPEND against existing OD-1..OD-24 and renumber any collision sequentially,
> preserving text. Show me the diffs before committing. Do not start any sprint work.

That lets Claude Code reconcile numbering against the live register (which this sandbox can't see)
and commit with your review — consistent with the commit-then-Leo-pushes rule.

## What is deliberately NOT here

**Sprint card rewrites (S04A, S04B, S05) and the three new cards (S06-BATCH, S-SELFREG, S-QFPAY).**
These are what Claude Code executes against and each deserves the same pre-sprint review every card
has had. Writing six cards now, unreviewed, would break that discipline. They are written in the
build session, one at a time, reviewed before their sprint — with all decisions above as the input.

## Nothing built needs unpicking

S00–S02B are on main, tagged. The audit found **no conflict** with anything built:
every new decision is additive (self-registration, batch, team payment) or lands in sprints not yet
started (S04A onward). S03 (consent, in build) is unaffected — the fresh-consent-per-cohort and
consent-gates-成團 rules are enforced at enrolment/team time (S04A/S05), not in the consent engine.

## The dead-loop audit (18 checks) is folded into OD-29 through OD-59

Every exception now has enumerated terminal actions; every wait state has a time-boxed backstop
(notably the 90-day auto-refund on parked roll-forwards). No state can be entered without a defined
exit. Details in the OD-APPEND rows and in section 8 of the client workflow PDF.

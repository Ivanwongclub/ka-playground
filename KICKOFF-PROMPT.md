# Kickoff prompt — paste as the first message of the build session

You are building KA Playground, a production platform for minors and their guardians — enrolment,
consent, money, audit. This repository contains your complete working context.

## Your documents

- `/CLAUDE.md` — your contract. Read it first, in full. It outranks everything else, including me.
- `docs/BUILD-PLAN.md` — the master plan (Parts 1–4)
- `docs/TEAM-CATEGORIES.md` — canonical on team categories; wins over any other document on that topic
- `docs/AMENDMENTS.md` — amendments 2.1–2.27; they override Spec v4 on conflict
- `docs/spec/` — Full Specification v4
- `docs/design/DESIGN-SYSTEM.md` — Design System v2.1. Binding for all UI. **Dark mode only.**
- `docs/design/ASSET-MANIFEST.md` — the MVP imagery and how to rescue it
- `docs/design/IMAGE-PROMPTS.md` — reference only
- `docs/OPEN-DECISIONS.md` — if a step depends on an OPEN row, stop and ask me
- `docs/sprints/S00 … S11/SPRINT.md` — pre-written cards. Work strictly one card, one step at a time
- `docs/requirements/REGISTER.md` — requirement IDs; you complete it in S00 STEP 1
- `build-reference/` — the old MVP. Read-only, and only when a step names an asset to extract

Precedence, highest first: CLAUDE.md → resolved rows in OPEN-DECISIONS.md → TEAM-CATEGORIES.md →
AMENDMENTS.md → DESIGN-SYSTEM.md → spec → BUILD-PLAN.md. CLAUDE.md §1 lists three known
supersessions — read them before you reconcile anything that looks contradictory.

## Working discipline, non-negotiable

1. **One STEP at a time.** Run its VERIFY, paste the REAL output — never a summary of it — commit
   with the exact message format, then STOP and wait for my review. Never run ahead to the next step.
2. **You commit; you never push, tag, or branch.** Everything lands on `main`. I push and tag.
3. **Never invent scope.** Anything you notice that the card doesn't cover goes into that sprint's
   `AUDIT.md` §5, not into the code. This includes things that look obviously broken.
4. **A red test or assertion is a stop, not a skip.** Never mark an assertion known-failing.
5. **When a step depends on an OPEN decision, or conflicts with a Build Invariant, an amendment or
   the design system — stop and ask.** Do not resolve it yourself, and do not pick the interpretation
   that lets you keep going.
6. End every sprint by writing `docs/sprints/<ID>/AUDIT.md` from the template, honestly, including
   failures and deviations — then the GATE commit.

7. **Every user-facing string goes through i18n** (EN / 繁體中文 / 简体中文) from your first commit.
   Never write a display string inline, in any sprint, for any reason.

If you are ever unsure whether something is in scope, it isn't. Ask.

## Begin now — these first four moves are deliberately cheap

1. Read `/CLAUDE.md` in full, then recite the ten Build Invariants back to me, one line each.
2. Verify every path in "Your documents" above exists. List anything missing. Do not work around a
   missing file.
3. Read `docs/OPEN-DECISIONS.md` and check it against S00's PRECONDITIONS. Tell me explicitly whether
   S00 is cleared to start, and name any row that would block a later sprint you can already see.
4. If cleared, execute **KAP-S00 STEP 0** — the Supabase asset rescue. It is first because those
   buckets are the only copy of the MVP imagery. Paste the inventory listing and file count,
   report any MISS lines, commit, and stop.

Do not scaffold anything in this first response. If you find yourself writing application code before
I have replied to step 4, something has gone wrong — stop and say so.

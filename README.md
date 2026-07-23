# KA Playground — Build Folder

This folder is the repo-plane documentation for the KAP production build. Drop it into the
repository root (merge with the scaffold Sprint 0 creates).

## Layout
```
CLAUDE.md                      ← agent contract, loaded every Claude Code session
KICKOFF-PROMPT.md              ← paste this to start the build chat
docs/
├── BUILD-PLAN.md              ← ⚠️ PLACE the exported plan (KA_Playground_Build_Plan_v1.md) here
├── AMENDMENTS.md              ← amendments 2.1–2.27 consolidated (overrides Spec v4 on conflict)
├── OPEN-DECISIONS.md          ← items that gate sprints; keep current
├── spec/                      ← ⚠️ PLACE Full Specification v4 here
├── requirements/REGISTER.md   ← requirement ID register (seeded; completed in S00)
└── sprints/
    ├── _TEMPLATE/AUDIT.md
    └── S00 … S11/SPRINT.md    ← pre-written cards (S04 split into S04A/S04B)
build-reference/               ← ⚠️ PLACE the MVP codebase here (read-only, excluded from builds)
```

## Sprint order
S00 → S01 → S02 → S03 → S04A → S04B → S05 → S06 → S07 → S08 → S09 → S10 → S11(post-UAT)

Cards for later sprints are deliberately scope-level: after each gate, review the AUDIT.md and
adjust the NEXT card before starting it. Adjusting a future card is normal; adjusting the current
one mid-flight is not — finish or fail the gate first.

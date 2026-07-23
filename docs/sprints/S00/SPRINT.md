# SPRINT KAP-S00 — Foundation & kickoff

> Read `/CLAUDE.md` first. It outranks this card.

## GOAL
A scaffolded, audited, deployable skeleton: every write from S01 onward is captured, uploads are
safe, CI gates migrations, the MVP imagery is rescued before it can be lost, and the reconciliation
runner exists for all future assertions.

## PRECONDITIONS
- [x] OD-8 (branch model) — all-in-main + tags · OD-9 (repo) — `ka-playground`
- [ ] `docs/spec/` contains Full Specification v4; `docs/BUILD-PLAN.md` in place
- [ ] `docs/design/` contains DESIGN-SYSTEM.md, ASSET-MANIFEST.md, IMAGE-PROMPTS.md
- [ ] `./build-reference/` contains the MVP codebase

## IMPLEMENTS  2.12 · 2.14 (envs) · 2.15 · 2.26 (runbook) · BI-1 · BI-10 · Design System v2.1 §2–§17

## STEPS

### STEP 0 — Asset rescue (do this first; it is time-critical)
**DO** Run the rescue script in `docs/design/ASSET-MANIFEST.md` §2 from the repo root. Every image
except the bundled logo and favicon lives only in the MVP's Supabase Storage — those buckets are the
single copy. Download into `build-reference/assets/` and commit immediately. No application code.
**NON-SCOPE** Wiring assets into the app (STEP 6). Reading MVP source (CLAUDE.md §5 still applies).
**VERIFY** Paste the script's inventory listing and total file count. Report any `MISS` lines.
**COMMIT** `KAP-S00-0: rescue MVP asset library from Supabase` · STOP.

### STEP 1 — Requirement register completion
**DO** Read `docs/spec/` and assign FR/SR IDs to Spec v4 parts in `docs/requirements/REGISTER.md`,
mapping each amendment to the requirement it modifies. No code.
**VERIFY** `grep -c '^| FR' docs/requirements/REGISTER.md` → non-trivial count; paste the table head.
**COMMIT** `KAP-S00-1: requirement register from spec v4` · STOP.

### STEP 2 — Scaffold + theme
**DO** Laravel 12 API (`api/`) + React/Vite/AntD Pro (`web/`) + Docker Compose (app, Postgres, Redis,
Horizon, Nginx). Theme per `docs/design/DESIGN-SYSTEM.md`: **`darkAlgorithm` only, no light mode, no
theme toggle**; `cssVar: true`, `hashed: false`; app wrapped in antd's `<App>` with all
toasts/confirms via `App.useApp()`; shared chart theme object; sidebar via fixed component tokens
per §3.2. Mobile app-shell primitives per §17: bottom tab bar (5 items, 56px + safe-area),
navigation drawer opened by avatar tap or left-edge swipe (**no hamburger anywhere**), bottom-sheet
component with snap points at 50%/92% and guarded swipe-to-close, PWA manifest.
**i18n scaffold (OD-19)**: locale files for `en`, `zh-TC`, `zh-SC`; AntD `ConfigProvider` locale wiring;
a locale switcher in the app shell; type stack extended with an SC fallback alongside the TC face.
**No user-facing string is written inline** — every one goes through the translation layer from this
commit onward. Add a CI check or documented review rule enforcing it.
`.dockerignore` excludes `build-reference/` and `docs/`.
**NON-SCOPE** No business entities. No auth flows (S01). No MVP code import.
**VERIFY** `docker compose up` healthy; `php artisan test` green (framework baseline);
`npm run build` succeeds; style-guide route renders the palette and one of each component state;
locale switcher cycles EN → TC → SC with the shell text changing and no missing-key warnings.
**COMMIT** `KAP-S00-2: scaffold + dark theme tokens + mobile shell` · STOP.

### STEP 3 — Audit spine
**DO** `audit_events` table with **DB-level INSERT-only enforcement** (rule/trigger rejecting
UPDATE/DELETE) + the audit service every future write path uses. Auth event types reserved per 2.11.
**VERIFY** Attempted UPDATE on `audit_events` fails at the DB; paste the error. Service writes one
event in a test, visible with actor identity.
**COMMIT** `KAP-S00-3: audit spine, INSERT-only enforced (BI-1)` · STOP.

### STEP 4 — Shared upload service (2.12)
**DO** One intake service: per-context MIME allow-list, size caps, image re-encode (strip EXIF),
queued ClamAV scan; file invisible until pass; quarantine + alert + audit on hit.
**VERIFY** EICAR test file → quarantined + audit event; clean image → visible only after scan job.
**COMMIT** `KAP-S00-4: shared upload service (BI-10)` · STOP.

### STEP 5 — Reconciliation runner
**DO** `php artisan reconcile:run [--tag=]` — a registry future sprints add assertions to; nightly
schedule; failures alert and write an audit event. Register assertion #1: audit immutability probe.
**VERIFY** `php artisan reconcile:run` → 1 assertion, green; paste output.
**COMMIT** `KAP-S00-5: reconciliation runner + immutability probe` · STOP.

### STEP 6 — Asset wiring, CI, environments, runbooks
**DO** Wire the STEP 0 tree into the app per ASSET-MANIFEST §3: logo into the ProLayout logo slot
(§11.1 rules — mono/light on aubergine, never gold, never recoloured, 28px min in the sider),
favicon into `index.html` (generate from the logo if the MVP's `favicon.ico` is absent), login
split-screen background from `auth-assets`. Serve from `public/assets/` behind an `ASSET_BASE_URL`
env var so the later OSS swap is one variable. Missing slots fall back to the category-colour
gradient per §12 — never a broken image.
CI: tests + `migrate --pretend` gate (2.15) + build. `deploy/` runbook per 2.26 (tagged images,
compose up, one-command rollback to previous tag). Staging compose variant per 2.14.
**NON-SCOPE** MVP source code — assets only.
**VERIFY** CI green on a no-op PR; asset tree listed; `build-reference/` absent from the built image;
logo, favicon and login background rendering.
**COMMIT** `KAP-S00-6: asset wiring + CI + deploy runbook` · STOP.

## AUDIT ELEMENT
Admin › Audit — Audit Log viewer, filterable by actor/entity/action/date.

## EXIT GATE
```
php artisan test
php artisan reconcile:run          # immutability probe green
docker compose config -q
cd web && npx tsc --noEmit && npm run build   # build embeds i18n:check + bundle budget (no chunk >1 MB gzipped)

# staging/production only — the app DB role must NOT own audit_events (BI-1 owner-bypass guard).
# Run as the APP's connection; must return t:
#   SELECT tableowner <> current_user AS app_is_not_owner FROM pg_tables WHERE tablename = 'audit_events';
```
+ audit viewer shows the events generated during this sprint
+ rescued asset inventory count recorded in AUDIT.md
+ style-guide route conforms to Design System v2.1 (dark only, gold discipline per §11.3)
+ all three locales render the shell with no missing keys and no hardcoded strings

Write AUDIT.md, commit `KAP-S00-GATE: …`.

## ROLLBACK  Pre-first-deploy: `git revert` only.

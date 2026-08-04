# KAP → GCP migration + full CI/CD — the plan (REVIEW BEFORE EXECUTE)

> **Status: PROPOSED. Nothing is deployed.** This is the reviewable plan + scripts.
> Leo executes only after the reviewer signs off **§2 (the RLS role setup)** and
> **§8 (the pipeline's gate wiring)**. The RLS proof (§3) then runs on the live
> Cloud SQL DB on **every** deploy before any traffic is promoted to the public URL.

Target shape: **Cloud Run (cloud-native)** — chosen over the single-VM path from
`GCP-MIGRATION-READINESS.md` because Leo wants **push-to-main auto-deploy with
gated promotion + instant revision rollback**, which is Cloud Run's strength. The
readiness doc's honest verdict still holds: this is the multi-day path, and its
gotchas (ClamAV, worker/scheduler, storage) are handled explicitly below.

## 0. The non-negotiable gate

**RLS-enforcement must be PROVEN on the deployed Cloud SQL DB before the URL is
public.** If the app connects as an owner/superuser (the easy Cloud SQL default),
`FORCE ROW LEVEL SECURITY` is silently bypassed and every child-data + money
boundary is wide open while the app looks fine (the kap_test trap). This is a
child-data exposure risk and gets the same rigor as every child-data card in the
build. The proof (§3) is a **hard gate**: a failure aborts the deploy and leaves
the current live revision up.

## 1. Files in this change

| File | What it is |
|---|---|
| `deploy/gcp/sql/01-roles-and-grants.sql` | **§2** reproducible role setup: `kap_migrate` (owner, DDL) + `kap_app` (runtime, NOSUPERUSER NOBYPASSRLS) + grants + immutability revokes |
| `deploy/gcp/rls-proof.sh` | **§3** THE HARD GATE — proves RLS enforces on the live DB as the app's own credential |
| `deploy/gcp/Dockerfile.api` | production php-fpm image (the repo's `api/Dockerfile` is dev-only, can't deploy) |
| `deploy/gcp/Dockerfile.web` | production image: build the SPA → nginx serving it + proxying `/api` |
| `deploy/gcp/nginx.conf` | Cloud Run ingress: SPA + fastcgi to the php-fpm sidecar |
| `deploy/gcp/cloudrun-web.yaml` | **§5** web service: nginx + api sidecar + GCS-FUSE + Cloud SQL |
| `deploy/gcp/cloudrun-worker.yaml` | **§4/§2** Horizon worker + clamd sidecar + GCS-FUSE |
| `deploy/gcp/setup.sh` | one-time provisioning (APIs, Cloud SQL, Redis, GCS, secrets, WIF, scheduler) |
| `deploy/gcp/rollback.sh` | **§10** one-command revision rollback |
| `.github/workflows/cd.yml` | **§8** the gated push-to-main pipeline |
| `api/database/seeders/DemoSeeder.php` | **§6** synthetic, strong-cred demo seed + RLS-proof fixtures |

## 2. THE RLS ROLE SETUP (highest stakes) — `sql/01-roles-and-grants.sql`

Two roles, reproducibly (idempotent SQL, not manual clicks):
- **`kap_migrate`** — owns every table; used ONLY by `php artisan migrate`. NOSUPERUSER, NOBYPASSRLS.
- **`kap_app`** — the runtime role the app connects as. Non-owner, NOSUPERUSER, NOBYPASSRLS, no DDL. RLS-subject.

Why it's fail-safe on Cloud SQL:
- Cloud SQL has **no true superuser** — the built-in `postgres` admin is not `rolsuper` and does **not** carry `BYPASSRLS`. Roles created here are NOSUPERUSER NOBYPASSRLS by default.
- The RLS policies + **`FORCE ROW LEVEL SECURITY`** live in the **migrations** (49 statements across 29 migrations), so `migrate` reproduces them from zero. `FORCE` means RLS applies **even to the table owner** — so even a deploy wrongly pointed at `kap_migrate` is RLS-subject.
- A misconfiguration therefore **breaks (app sees nothing)** rather than **leaks**. But "breaks not leaks" is not good enough for go-live — §3 proves it actually enforces.

Immutability revokes (BI-1/2/5/6) are **byte-identical** to the committed
`deploy/postgres/grant-app-role.sql`. The one known drift: that local script
hardcodes `ALTER DEFAULT PRIVILEGES FOR ROLE kap`; GCP standardises the owner as
`kap_migrate` (matching `deploy/RUNBOOK.md`). **Follow-up (flagged):** parameterise
the local script's owner name so a single source feeds both and they cannot drift.

Bring-up order (load-bearing — RLS lives in the migrations, not a data dump):
1. `migrate` as **`kap_migrate`** → tables + policies + `FORCE RLS` + immutability triggers.
2. run `sql/01-roles-and-grants.sql` → creates `kap_app`, grants DML, applies revokes.
3. seed (`DemoSeeder`, §6).
4. the app connects as **`kap_app`** (set in both Cloud Run service specs; `DB_USERNAME: kap_app`, password from the `kap-app-password` secret — never the owner secret).

> **#1 correctness check, encoded in §3:** the app must connect as `kap_app`, not `kap_migrate`/admin. The proof connects with the app's *own* secret and asserts `current_user = kap_app`, so a mispointed app fails the gate.

## 3. THE VERIFICATION GATE — `rls-proof.sh` (runs on the live DB, every deploy)

Connects to the **deployed** Cloud SQL DB **using the exact secret the app runs
with** (`kap-app-password`, via the Cloud SQL Auth Proxy) and asserts, faithfully
reproducing how `ScopeContext` gates (session GUCs `app.context` / `app.actor_id`
/ `app.actor_role` / `app.student_ids`, read by `current_setting()` in the policies):

1. **Identity** — `current_user = kap_app`; the role is **NOT** `rolsuper` and **NOT** `rolbypassrls`; the role does **not own** `audit_events`. (Catches the owner/superuser trap directly.)
2. **FORCE integrity** — every RLS-enabled table is also `FORCE`d (no silent flip); the core child-data/money tables (`guardian_links`, `enrolments`, `audit_events`, `payments`, `consent_signatures`) are FORCE-protected; the FORCE-RLS table count is at its expected floor.
3. **Deny-by-default** — with **no** context set, `guardian_links` returns **0 rows** (fail-closed ground state).
4. **Positive control** — guardian A, with A's context, sees A's own family rows **and only those**.
5. **Negative control (the point)** — guardian A **cannot** read guardian B's family link rows (**cross-family**), and **cannot** read child B's enrolments (**cross-child data**). Both must be 0.

Any failure → non-zero exit → the `promote` job never runs → **the candidate
revision stays at 0% traffic, the current live version keeps serving.** The
fixtures (guardian A, guardian B, child B ids) come from `DemoSeeder`'s
`RLS-PROOF-FIXTURES=` line, parsed by the pipeline.

The `guardian_links` policy predicate used in the proof (`guardian_id::text =
current_setting('app.actor_id')`) is copied from the source migration
`2026_07_31_140000_link_visibility_and_hardening.php` — not assumed.

## 4. ClamAV, the worker, the scheduler

- **ClamAV** — runs as a **sidecar in the worker service** (`cloudrun-worker.yaml`). The scan is a **queued job** (`ScanUpload`), so clamd's only consumer is the worker; putting clamd beside it keeps it at `127.0.0.1:3310`. It needs ~1.5–2 Gi RAM + a freshclam DB + ~180 s warmup, so the sidecar has a TCP **startup probe** and generous memory. **BI-10 holds fail-safe:** uploads stay invisible until the scan passes, so a clamd outage degrades (evidence doesn't surface) rather than leaks. *Verify before assuming:* if any **synchronous** scan path exists on the web tier, the web service needs clamd too — today it does not.
- **The worker (Horizon)** — its own Cloud Run service, **`minScale: 1` (always on)**, `cpu-throttling: false`. The async jobs are load-bearing (money-finalize, consent-PDF, evidence-scan); without a live worker the demo's core flows hang.
- **The scheduler** — **Cloud Scheduler → a `schedule:run` Cloud Run Job every minute** (`setup.sh` §9). Laravel's `routes/console.php` stays the source of truth (`reconcile:run` @03:00 HKT + aging/advancement/activation + `sessions:advance` /5 min).
- **Redis** — Memorystore (Horizon queue + cache).

## 5. Storage (GCS) and frontend↔API wiring

- **Storage** — the evidence bucket is mounted via **GCS-FUSE** at `storage/app` in **both** the web and worker services (`FILESYSTEM_DISK` stays `local`; the local path is GCS-backed). This is **required**, not optional: the web tier **writes** evidence and the worker **reads** it to scan — separate services must share one bucket. GCS-FUSE also **sidesteps a `composer require` for a Flysystem-GCS adapter**, which would be a CLAUDE.md STOP condition (a dependency the sprint card didn't name). *Alternative, deferred:* a native `gcs` disk needs that flagged composer add + a code change — not taken here.
- **Frontend↔API** — the SPA calls **relative `/api`**; nginx proxies it to the php-fpm sidecar (same-origin, no CORS, no baked API host). So there is **no build-time API host** to inject; the per-environment wiring is the **server** vars `APP_URL` / `APP_PUBLIC_URL` (the forwardable `/pay` link) — set to the public URL, **never** localhost.

## 6. The demo seed (`DemoSeeder`) — provably synthetic

Differs from the local-only `PreviewSeeder` on three deliberate points: runs under
`APP_ENV in {local, demo}` (never production); **strong credentials** from
`DEMO_SEED_PASSWORD` (Secret Manager) — refuses weak/absent; **synthetic-only**,
enforced (every email must be `@demo.ka` or it throws). It drives the **real**
services (enrolment, consent signing, `ManualPaymentService`) so the money /
consent / BI-9 / guardian surfaces have live data, and seeds a **second, unrelated
family** so §3 has a real cross-family boundary. It emits `RLS-PROOF-FIXTURES=…`.

**Honest gap (a named pre-deploy work item):** four surfaces are **flagged, not
faked** — `member(invite→accept)`, `teams(成團)`, `teacher`, `school_admin`. They
print a COVERAGE-GAP warning and need their real service flows authored + a **local
dry-run** before the demo is "complete." They do **not** block the RLS proof or the
money/consent/guardian demo. **Required before first deploy:** dry-run locally
(`APP_ENV=local DEMO_SEED_PASSWORD=… php artisan migrate:fresh --seed
--seeder=DemoSeeder`) and confirm `reconcile:run` is 58/58 on the seeded DB.

## 7. Security for a public, child-data-shaped URL

- **Synthetic-only, provable** — `@demo.ka` accounts, `(demo)` markers, no real PII; the seeder throws on a non-`@demo.ka` email.
- **Strong creds** — from Secret Manager, not `password`.
- **Write-enabled** (it must demo BI-9 confirm, enrolment, consent) → therefore **resettable**: a nightly Cloud Scheduler `migrate:fresh --seed DemoSeeder` on the demo DB, so client tinkering can't accrete/corrupt. `MAIL_MAILER=log` (a public demo emails no one), `APP_DEBUG=false` (no stack-trace/secret leakage).
- **Access-gated + labelled** — the demo should sit behind an access gate (**Cloud IAP** or a shared front-door auth) plus the app's own login, with a visible "**DEMO — synthetic data**" banner. A child-data-shaped app must be provably synthetic **and** not wide open. *(Gating is an infra toggle at go-live; the banner is a small frontend line-item — flagged, not in this change.)*
- RLS, BI-9, consent gates, immutability all **hold** on the public instance **because the app connects as `kap_app`** — which §3 proves on every deploy.

## 8. The CI/CD pipeline — `.github/workflows/cd.yml` (push-to-main auto-deploy, gated)

Ordered, and **aborts** (leaving the current live version up) if any stage fails:

1. **`backend`** — the full suite (~450+): BI-9/`ManualPaymentTest`, consent, RLS, attendance; the four real-clamd tests **must execute, not skip**.
2. **`reconcile`** — the 58 assertions, run **as `kap_app`** (RLS-subject). Exit 0 required.
3. **`frontend`** — `tsc` + `npm run build` (i18n:check, ds2:tokens, ds2:import-guard, marketing-payload, bundle-budget embedded).
4. **`build_images` → `deploy_candidate`** — build+push SHA-tagged images; migrate the demo DB as `kap_migrate`; refresh grants; reseed; **deploy the candidate web revision with `--no-traffic`** (it exists but is not public).
5. **`rls_proof`** — run §3 against the live Cloud SQL DB as `kap_app`. **HARD GATE.**
6. **`promote`** — only if 1–5 pass → route 100% traffic to the proven revision (the public URL).

**How the block is genuinely enforced (what the reviewer verifies):**
- Each deploy job has `needs:` on the stage before it; a job whose dependency fails is **skipped**. `promote` `needs: rls_proof` `needs: deploy_candidate` `needs: [backend, reconcile, frontend]` — **there is no path to `promote` that skips the tests or the RLS proof.**
- **No** `continue-on-error`, **no** `if: always()` anywhere — a red result cannot be stepped over.
- The candidate carries **no public traffic** until `promote`. Abort = it never goes live.
- Auth is **Workload Identity Federation** — no long-lived JSON keys in the repo.

## 9. Residual risk (Leo-accepted)

**Full CI/CD has no human review gate.** The automated gates cover **regressions in
KNOWN invariants** — BI-9 SoD, RLS enforcement, the reconcile battery, immutability,
the frontend contracts. **A novel bug that no existing test anticipates can still
reach live.** Leo accepts this trade for auto-deploy velocity; the mitigation is
that the invariants with the highest blast radius (child-data via RLS, money via
BI-9 + reconcile) are exactly the ones gated on every deploy, including a probe of
the real deployed DB.

Secondary residual risk — **single demo DB**: the candidate's migrations run
against the shared demo DB *before* promote, so a bad **migration** touches data
even when traffic doesn't move. Mitigations: CI's `migrate --pretend` + the full
suite gate migrations; **migrations must be expand-contract** (backward-compatible)
so the currently-live revision keeps working if a later gate aborts. For production
(not this demo), use a **separate staging DB/project** so migrations are proven off
the live data first.

## 10. Rollback

Cloud Run keeps every revision immutable; rollback is a **traffic move, not a
rebuild** — near-instant, no CI:

```bash
./deploy/gcp/rollback.sh            # → the previous revision
./deploy/gcp/rollback.sh --list     # revisions + current traffic split
./deploy/gcp/rollback.sh <REVISION> # → a specific revision
# under the hood:
gcloud run services update-traffic kap-web --region=$REGION --to-revisions=<PRIOR>=100
```

This reverts **code** only. If an aborted/bad deploy had already migrated the
shared demo DB, a schema rollback is separate — expand-contract migrations keep the
prior revision working; a genuinely destructive change needs a Cloud SQL restore
(`deploy/RUNBOOK.md` backup/restore).

## 11. Honest "how much troubleshooting" (unchanged from readiness)

This is the **multi-day** path, not push-button. Ranked by expected pain:
**ClamAV sidecar** (RAM/warmup) > **the `DemoSeeder` gaps** (member/teams/teacher/
school, + the local dry-run) > **worker/scheduler wiring** (Cloud Scheduler + the
always-on worker) > **GCS-FUSE storage** (gen2 + the shared bucket) > **the WIF /
Cloud SQL / role setup** (mechanical, once) > **the `APP_PUBLIC_URL` / access-gate /
demo banner** line-items. What's genuinely low-friction: config is fully
externalised, the RLS policies are reproducible in migrations, migrations run clean
from zero, and the frontend is same-origin relative-`/api`.
```

**Bottom line for the reviewer:** the two things to sign off before Leo executes
are **§2 + `sql/01-roles-and-grants.sql`** (the app can only ever be a non-owner,
RLS-subject role) and **§3 + §8** (the RLS proof genuinely runs on the live DB and
the pipeline's `needs:` DAG genuinely blocks promotion on any failure). Everything
else is standard cloud plumbing.

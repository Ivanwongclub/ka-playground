# KAP deploy & rollback runbook (2.26) · environments (2.14)

**Environments:** `local → staging → production`. Staging mirrors the production
compose with its own ApsaraDB RDS database and OSS bucket. Nothing reaches
production without passing staging. Leo pushes and tags; every production deploy
is an **annotated tag**.

## Databases

- **Local & CI:** the compose `postgres` service — app DB `kap`, **test DB `kap_test`**
  (host port 54329). There is exactly one way to run the suite: `php artisan test`
  against the compose Postgres. SQLite is not a supported test target — BI-2/BI-3
  behaviour (gapless sequences, `SELECT … FOR UPDATE`) only means anything on Postgres.
- **Staging/production:** ApsaraDB RDS. The application connects as **`kap_app`, a
  non-owner role**. Tables are owned by a separate migration role. This is a BI-1
  requirement, not a convention — an owner can disable the INSERT-only trigger.

## Provisioning a new environment (once)

1. Create RDS database and two roles: `kap_migrate` (owner, used only for
   `php artisan migrate`) and `kap_app` (runtime; no ownership, no DDL).
2. `GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES … TO kap_app;` then
   `REVOKE UPDATE, DELETE ON audit_events, receipts, consent_signatures,
   consent_template_versions, credit_notes FROM kap_app;` (Spec N12).
3. **Verify the owner guard** (S00 exit gate — a query, not prose):
   ```sh
   PGPASSWORD=*** ./deploy/check-audit-owner.sh -h <rds-host> -U kap_app -d kap
   # must print: OK: app role does not own audit_events
   ```
4. OSS: bucket per environment; versioning ON for `receipts/` and
   `consent-documents/` prefixes (2.14).

## Deploy (staging, then production)

1. CI green on the commit (CI runs the exact exit-gate commands — see
   `.github/workflows/ci.yml`).
2. Build and push tagged images: `kap-api:<tag>`, `kap-web:<tag>` — `<tag>` is the
   annotated git tag.
3. `docker compose -f compose.staging.yaml pull && docker compose -f compose.staging.yaml up -d`
4. Migrations: `docker compose -f compose.staging.yaml run --rm --user … app php artisan migrate --force`
   **as `kap_migrate`** — CI has already run `migrate --pretend` (2.15); any migration
   flagged destructive requires review before this step.
5. Post-deploy checks: `/up` returns 200 · `php artisan reconcile:run` exit 0 ·
   `./deploy/check-audit-owner.sh` OK.
6. Production: repeat 3–5 with the production env file. Tag the release (Leo).

## Rollback (one command)

```sh
TAG=<previous-tag> docker compose -f compose.staging.yaml up -d
```

Images are immutable per tag, so rolling back is redeploying the previous tag.
If the destructive-migration review for the rolled-back release decided a DB
restore is also needed, restore the RDS snapshot from before the deploy
(nightly snapshots retained 7 days, weekly 4 weeks — 2.14), then re-run
`reconcile:run` and the owner guard before reopening traffic.
Rollback is rehearsed on staging in S10 (2.26).

## Backup & restore (2.14)

- RDS automated nightly snapshots retained 7 days + weekly retained 4 weeks.
- OSS versioning on receipts and consent-documents.
- Restore drill before go-live, then quarterly; results logged. A backup that has
  never been restored is a hope, not a backup.

## Local-only test artifacts

`deploy/clamav/kap-test.ndb` (the harmless KAP scan-test signature) is mounted
ONLY by the local `compose.yaml`. `compose.staging.yaml` must never mount it.

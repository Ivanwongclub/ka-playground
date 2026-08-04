-- ============================================================================
-- KAP · Cloud SQL role setup — REPRODUCIBLE (run once per environment, idempotent)
-- ============================================================================
-- Highest-stakes file in the migration. Its job: guarantee the Laravel app can
-- ONLY ever connect as a NON-OWNER, NOSUPERUSER, NOBYPASSRLS role, so RLS is
-- FORCE-enforced against it. If the app connects as the owner/superuser, every
-- FORCE ROW LEVEL SECURITY policy is bypassed and every child-data / money
-- boundary is silently open (the kap_test trap). This file + rls-proof.sh are
-- the two things the reviewer signs off before first deploy.
--
-- Owner-role naming: the RUNBOOK's staging/production convention is
--   kap_migrate  = table OWNER, used ONLY by `php artisan migrate` (DDL)
--   kap_app      = runtime, NON-owner, NOSUPERUSER NOBYPASSRLS (the app uses this)
-- (Local/CI's deploy/postgres/grant-app-role.sql hardcodes owner = `kap`; GCP
-- standardises on kap_migrate. The immutability-revoke list below is kept BYTE-
-- IDENTICAL to that file — if one changes, change both. Follow-up: parameterise
-- the local script's `FOR ROLE kap` so a single source feeds both.)
--
-- Cloud SQL note: the instance's built-in admin (`postgres`) is NOT a real
-- superuser and does NOT carry BYPASSRLS — roles created here are NOSUPERUSER
-- NOBYPASSRLS by default, so a misconfiguration FAILS SAFE (the app sees nothing)
-- rather than leaking. FORCE RLS applies even to the table owner, so even a
-- deploy wrongly pointed at kap_migrate is RLS-subject.
--
-- Run as the Cloud SQL admin, connected to the KAP database:
--   psql "host=<proxy> user=postgres dbname=kap" \
--     -v app_pw="$(...secret...)" -v migrate_pw="$(...secret...)" \
--     -f deploy/gcp/sql/01-roles-and-grants.sql
-- Passwords are passed as psql vars (never committed); pull them from Secret Manager.
-- ============================================================================

\set ON_ERROR_STOP on

-- 1) OWNER role (DDL only). Owns every table; runs migrations. Never used at runtime.
DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kap_migrate') THEN
    EXECUTE format('CREATE ROLE kap_migrate LOGIN PASSWORD %L NOSUPERUSER NOBYPASSRLS CREATEDB', :'migrate_pw');
  END IF;
END $$;

-- 2) RUNTIME role (the app). NON-owner, NOSUPERUSER, NOBYPASSRLS — RLS-subject.
DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kap_app') THEN
    EXECUTE format('CREATE ROLE kap_app LOGIN PASSWORD %L NOSUPERUSER NOBYPASSRLS', :'app_pw');
  END IF;
END $$;

-- Belt-and-braces: even if the roles pre-existed, force the safe attributes.
ALTER ROLE kap_app    NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
ALTER ROLE kap_migrate NOSUPERUSER NOBYPASSRLS;

-- 3) Ownership of the schema goes to kap_migrate; the app only USEs it.
ALTER SCHEMA public OWNER TO kap_migrate;
GRANT USAGE ON SCHEMA public TO kap_app;

-- 4) Runtime DML grants (SELECT/INSERT/UPDATE/DELETE). DDL is NEVER granted.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kap_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kap_app;
-- New tables created by future kap_migrate migrations inherit the same grants.
ALTER DEFAULT PRIVILEGES FOR ROLE kap_migrate IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kap_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kap_migrate IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO kap_app;

-- 5) Immutability revokes — BI-1/2/5/6 append-only spine. IDENTICAL list to
--    deploy/postgres/grant-app-role.sql. These override the blanket grant above.
REVOKE UPDATE, DELETE, TRUNCATE ON audit_events            FROM kap_app; -- BI-1 audit spine
REVOKE UPDATE, DELETE, TRUNCATE ON programme_versions      FROM kap_app;
REVOKE UPDATE, DELETE, TRUNCATE ON consent_signatures      FROM kap_app; -- BI-6 (S03)
REVOKE UPDATE, DELETE, TRUNCATE ON consent_documents       FROM kap_app; -- FR038 (S03)
REVOKE UPDATE, DELETE, TRUNCATE ON order_lines             FROM kap_app; -- BI-5 immutable lines
REVOKE UPDATE, DELETE, TRUNCATE ON receipts                FROM kap_app; -- BI-2 gapless receipts
REVOKE UPDATE, DELETE, TRUNCATE ON credit_notes            FROM kap_app; -- BI-5 corrections
REVOKE UPDATE, DELETE, TRUNCATE ON reconciliation_log      FROM kap_app; -- SR010 run records
REVOKE UPDATE, DELETE, TRUNCATE ON payment_evidence        FROM kap_app; -- append-only evidence
REVOKE UPDATE, DELETE, TRUNCATE ON withdrawal_endorsements FROM kap_app; -- append-only pastoral

-- 6) Fail-loud sanity: the runtime role must not have escaped its cage.
DO $$
DECLARE bad int;
BEGIN
  SELECT count(*) INTO bad FROM pg_roles
   WHERE rolname = 'kap_app' AND (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole);
  IF bad > 0 THEN
    RAISE EXCEPTION 'kap_app has escalated attributes — refusing to leave it configured';
  END IF;
END $$;

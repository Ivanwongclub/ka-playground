-- ============================================================================
-- KAP · Cloud SQL PER-DEPLOY re-grant — idempotent GRANTs + immutability REVOKEs
-- ============================================================================
-- Runs on EVERY deploy (cd.yml kap-grant job), AFTER `migrate`, because the
-- immutability REVOKEs (BI-1/2/5/6) target tables that migrate has just created,
-- and new tables need the runtime DML grant.
--
-- It creates NO roles and needs NO passwords — the roles (kap_migrate owner,
-- kap_app runtime, both NOSUPERUSER NOBYPASSRLS) are provisioned ONCE, by hand,
-- via deploy/gcp/sql/01-roles-and-grants.sql (setup.sh). So the pipeline runs this
-- with `psql -v ON_ERROR_STOP=1 -f` and NO -v password vars. (01 keeps the
-- CREATE ROLE ... PASSWORD :'...' lines; running it in the pipeline without those
-- vars is what failed at parse time — this file removes that coupling entirely.)
--
-- Run as the OWNER role kap_migrate (it owns the tables migrate created, so it can
-- GRANT/REVOKE on them and ALTER its own default privileges). Fail-loud: any error
-- aborts (ON_ERROR_STOP), the kap-grant job fails, and the deploy does NOT promote —
-- the append-only guarantees are a go-live precondition, never silently skipped.
--
--   psql -v ON_ERROR_STOP=1 -f deploy/gcp/sql/02-grants-and-revokes.sql
--   (PGUSER=kap_migrate PGHOST=/cloudsql/<instance> PGDATABASE=kap)
-- ============================================================================

\set ON_ERROR_STOP on

-- Fail loud if provisioning never ran — do NOT silently no-op the grants.
DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kap_app')
     OR NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kap_migrate') THEN
    RAISE EXCEPTION 'kap_app/kap_migrate not provisioned — run 01-roles-and-grants.sql (setup.sh) first';
  END IF;
END $$;

-- Runtime DML grants (SELECT/INSERT/UPDATE/DELETE). DDL is NEVER granted.
GRANT USAGE ON SCHEMA public TO kap_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kap_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kap_app;
-- New tables created by future kap_migrate migrations inherit the same grants.
ALTER DEFAULT PRIVILEGES FOR ROLE kap_migrate IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kap_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kap_migrate IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO kap_app;

-- Immutability revokes — BI-1/2/5/6 append-only spine. IDENTICAL list to
-- 01-roles-and-grants.sql and deploy/postgres/grant-app-role.sql. These override
-- the blanket grant above.
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

-- Fail-loud sanity: the runtime role must not have escaped its cage.
DO $$
DECLARE bad int;
BEGIN
  SELECT count(*) INTO bad FROM pg_roles
   WHERE rolname = 'kap_app' AND (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole);
  IF bad > 0 THEN
    RAISE EXCEPTION 'kap_app has escalated attributes — refusing to leave it configured';
  END IF;
END $$;

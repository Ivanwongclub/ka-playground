-- Idempotent: the non-superuser runtime role (RLS is inert for superusers).
-- Mirrors the runbook's kap_migrate/kap_app split locally. Run per database.
DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kap_app') THEN
    CREATE ROLE kap_app LOGIN PASSWORD 'app_local_only' NOSUPERUSER NOBYPASSRLS;
  END IF;
END $$;

GRANT USAGE ON SCHEMA public TO kap_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kap_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kap_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kap IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kap_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kap IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO kap_app;

-- Immutability revokes take precedence over the blanket grant
REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM kap_app;
REVOKE UPDATE, DELETE, TRUNCATE ON programme_versions FROM kap_app;
REVOKE UPDATE, DELETE, TRUNCATE ON consent_signatures FROM kap_app; -- BI-6 evidence (S03)
REVOKE UPDATE, DELETE, TRUNCATE ON consent_documents FROM kap_app; -- FR038 evidence (S03)
REVOKE UPDATE, DELETE, TRUNCATE ON order_lines FROM kap_app; -- BI-5 immutable lines (S04B-1)
REVOKE UPDATE, DELETE, TRUNCATE ON receipts FROM kap_app; -- BI-2 immutable receipts (S04B-1)
REVOKE UPDATE, DELETE, TRUNCATE ON credit_notes FROM kap_app; -- BI-5 immutable corrections (S04B-5)

#!/usr/bin/env sh
# BI-1 owner guard (S00 exit gate, staging/production): the APP database role
# must NOT own audit_events — an owner can disable the INSERT-only trigger.
# Run with the APP's connection parameters, e.g.:
#   PGPASSWORD=... ./deploy/check-audit-owner.sh -h <rds-host> -U kap_app -d kap
# Exit 0 = safe. Non-zero = the deploy must not proceed.
set -eu

RESULT=$(psql -tA "$@" -c "SELECT tableowner <> current_user FROM pg_tables WHERE tablename = 'audit_events';")

case "$RESULT" in
  t) echo "OK: app role does not own audit_events (BI-1 owner guard holds)" ;;
  f) echo "FAIL: the app role OWNS audit_events — an owner bypasses the BI-1 trigger. Reassign ownership before deploying."; exit 1 ;;
  *) echo "FAIL: audit_events not found or query failed (result: '$RESULT')"; exit 1 ;;
esac

#!/usr/bin/env bash
# ============================================================================
# KAP · RLS-ENFORCEMENT PROOF — THE HARD DEPLOY GATE
# ============================================================================
# Runs AGAINST THE DEPLOYED DATABASE, connecting with THE EXACT CREDENTIAL THE
# APP RUNS WITH (kap_app, pulled from the same Secret Manager secret the Cloud
# Run service mounts as DB_PASSWORD). It PROVES row-level security is actually
# enforcing on child-data and money boundaries. ANY failure -> exit non-zero ->
# the pipeline ABORTS, leaves the current live revision up, and does NOT promote.
#
# Why this exists: if the app is (mis)pointed at the owner/superuser role, or a
# migration silently drops FORCE RLS, the app keeps WORKING while every RLS
# policy is bypassed — a silent, total child-data + money exposure (the kap_test
# trap). No unit test on a build server can catch that; only a probe of the
# LIVE, DEPLOYED db, as the app's own role, can. So this runs on EVERY deploy.
#
# Connection: standard libpq env — PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE.
# The CD job sets PGUSER=kap_app and PGPASSWORD=<the app's runtime secret>,
# reaching Cloud SQL through the Auth Proxy. Do NOT run this as kap_migrate/postgres.
#
# Fixtures: three ids emitted by DemoSeeder's "RLS-PROOF-FIXTURES" line —
#   RLS_GUARDIAN_A  a seeded guardian
#   RLS_GUARDIAN_B  a DIFFERENT guardian, NOT family-linked to A
#   RLS_CHILD_B     a child of B (whom A must never be able to read)
# ============================================================================
set -euo pipefail

: "${PGHOST:?set PGHOST}" "${PGUSER:?set PGUSER}" "${PGPASSWORD:?set PGPASSWORD}" "${PGDATABASE:?set PGDATABASE}"
: "${RLS_GUARDIAN_A:?}" "${RLS_GUARDIAN_B:?}" "${RLS_CHILD_B:?}"
EXPECT_APP_ROLE="${EXPECT_APP_ROLE:-kap_app}"
export PGOPTIONS='--client-min-messages=warning'

pass=0
ok()   { echo "  PASS · $1"; pass=$((pass+1)); }
die()  { echo "RLS-PROOF FAIL · $1" >&2; echo; echo ">>> GATE FAILED — deploy ABORTED, live revision untouched, NO promote." >&2; exit 1; }

# q RUNS ONE query in a fresh connection (no context) and echoes the scalar.
q()  { psql -v ON_ERROR_STOP=1 -tA -c "$1"; }
# qc SETS a guardian request-context, then runs the query IN THE SAME SESSION.
# set_config(...,false) is session-scoped and persists across -c statements in
# one psql invocation — this reproduces exactly how ScopeContext::set() gates.
qc() { # $1=actor_id  $2=student_ids_csv  $3=query
  psql -v ON_ERROR_STOP=1 -tA \
    -c "SELECT set_config('app.context','request',false), set_config('app.actor_role','guardian',false), set_config('app.actor_id','$1',false), set_config('app.student_ids','$2',false);" \
    -c "$3"
}

echo "RLS-ENFORCEMENT PROOF against ${PGDATABASE}@${PGHOST} as ${PGUSER}"
echo "------------------------------------------------------------------"

# ── 1. IDENTITY: the app's live credential is the caged runtime role ──────────
[ "$(q 'SELECT current_user')" = "$EXPECT_APP_ROLE" ] \
  || die "app connected as '$(q 'SELECT current_user')', expected '$EXPECT_APP_ROLE' (owner/superuser trap)"
ok "connected as $EXPECT_APP_ROLE (non-owner runtime role)"

[ "$(q "SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname = current_user")" = "f" ] \
  || die "app role is SUPERUSER or BYPASSRLS — RLS would be bypassed"
ok "app role is NOSUPERUSER and NOBYPASSRLS"

[ "$(q "SELECT tableowner = current_user FROM pg_tables WHERE tablename = 'audit_events'")" = "f" ] \
  || die "app role OWNS audit_events — an owner bypasses FORCE RLS and the BI-1 trigger"
ok "app role does NOT own the tables (BI-1 owner guard)"

# ── 2. FORCE RLS INTEGRITY: no RLS table left un-FORCEd (catches a silent flip) ─
[ "$(q "SELECT count(*) FROM pg_class WHERE relkind='r' AND relrowsecurity AND NOT relforcerowsecurity")" = "0" ] \
  || die "$(q "SELECT count(*) FROM pg_class WHERE relkind='r' AND relrowsecurity AND NOT relforcerowsecurity") RLS table(s) have RLS enabled but NOT FORCEd"
ok "every RLS-enabled table is also FORCE ROW LEVEL SECURITY"

[ "$(q "SELECT bool_and(relrowsecurity AND relforcerowsecurity) FROM pg_class WHERE relname IN ('guardian_links','enrolments','audit_events','payments','consent_signatures')")" = "t" ] \
  || die "a core child-data/money table lost FORCE RLS"
ok "core child-data + money tables are FORCE-protected"

[ "$(q "SELECT (count(*) >= 20) FROM pg_class WHERE relforcerowsecurity")" = "t" ] \
  || die "only $(q "SELECT count(*) FROM pg_class WHERE relforcerowsecurity") FORCE-RLS tables — expected the full policy set (~49); RLS may be partially dropped"
ok "$(q "SELECT count(*) FROM pg_class WHERE relforcerowsecurity") tables carry FORCE ROW LEVEL SECURITY"

# ── 3. DENY-BY-DEFAULT: no request context ⇒ policies match nothing ───────────
[ "$(q 'SELECT count(*) FROM guardian_links')" = "0" ] \
  || die "guardian_links readable with NO context set — fail-closed ground state is broken"
ok "no-context read of guardian_links returns 0 rows (fail-closed)"

# ── 4. POSITIVE CONTROL: guardian A sees their OWN family, and only that ──────
A_TOTAL="$(qc "$RLS_GUARDIAN_A" "" "SELECT count(*) FROM guardian_links")"
A_OWN="$(qc "$RLS_GUARDIAN_A" "" "SELECT count(*) FROM guardian_links WHERE guardian_id::text = '$RLS_GUARDIAN_A'")"
[ "$A_TOTAL" -gt 0 ] || die "guardian A sees 0 of their own links — positive control failed (policy or seed wrong)"
[ "$A_TOTAL" = "$A_OWN" ] || die "guardian A sees $A_TOTAL link rows but only $A_OWN are theirs — LEAK"
ok "guardian A sees their own $A_OWN link row(s) and nothing else"

# ── 5. NEGATIVE CONTROL: cross-family / cross-child reads are DENIED ───────────
[ "$(qc "$RLS_GUARDIAN_A" "" "SELECT count(*) FROM guardian_links WHERE guardian_id::text = '$RLS_GUARDIAN_B'")" = "0" ] \
  || die "guardian A can read guardian B's family link rows — CROSS-FAMILY LEAK"
ok "guardian A cannot read guardian B's link rows (cross-family denied)"

# Child-data: A (with A's own student scope) must not see B's child's enrolments.
[ "$(qc "$RLS_GUARDIAN_A" "" "SELECT count(*) FROM enrolments WHERE student_id::text = '$RLS_CHILD_B'")" = "0" ] \
  || die "guardian A can read child B's enrolment rows — CROSS-CHILD DATA LEAK"
ok "guardian A cannot read child B's enrolments (cross-child denied)"

echo "------------------------------------------------------------------"
echo "RLS-PROOF PASS · ${pass}/${pass} assertions · RLS is ENFORCING on the live DB."

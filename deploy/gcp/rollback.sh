#!/usr/bin/env bash
# ============================================================================
# KAP · Cloud Run rollback — instant revert to the previous revision.
# ============================================================================
# Cloud Run keeps every revision immutable. A rollback is a TRAFFIC move, not a
# rebuild — so it is near-instant and needs no CI. Use it if a build that somehow
# passed every gate still misbehaves in front of the client.
#
#   ./deploy/gcp/rollback.sh                 # roll back to the prior revision
#   ./deploy/gcp/rollback.sh <REVISION>      # roll back to a specific revision
#   ./deploy/gcp/rollback.sh --list          # show revisions + current traffic
set -euo pipefail

REGION="${GCP_REGION:-asia-east1}"
SERVICE="${WEB_SERVICE:-kap-web}"

if [ "${1:-}" = "--list" ]; then
  gcloud run revisions list --service="$SERVICE" --region="$REGION" \
    --format='table(metadata.name, status.conditions[0].lastTransitionTime, spec.containers[0].image)'
  echo "--- current traffic ---"
  gcloud run services describe "$SERVICE" --region="$REGION" \
    --format='table(status.traffic[].revisionName, status.traffic[].percent)'
  exit 0
fi

TARGET="${1:-}"
if [ -z "$TARGET" ]; then
  # Second-newest revision = the one serving before the last promote.
  TARGET=$(gcloud run revisions list --service="$SERVICE" --region="$REGION" \
    --sort-by='~metadata.creationTimestamp' --format='value(metadata.name)' | sed -n '2p')
fi
[ -n "$TARGET" ] || { echo "No prior revision found to roll back to." >&2; exit 1; }

echo "Rolling $SERVICE back to: $TARGET"
gcloud run services update-traffic "$SERVICE" --region="$REGION" --to-revisions="$TARGET=100"

echo "Done. $TARGET now serves 100% of traffic."
echo "NOTE: this reverts CODE only. If the aborted deploy had already migrated the"
echo "shared demo DB, a schema rollback is separate — migrations are expand-contract,"
echo "so the prior revision keeps working; a destructive change needs a DB restore"
echo "(see docs/deploy/GCP-MIGRATION.md §9 residual risk)."

#!/usr/bin/env bash
# ============================================================================
# KAP · GCP one-time provisioning (run ONCE per environment, by Leo, by hand).
# ============================================================================
# Idempotent where it can be. Creates the project scaffolding the CD pipeline
# (cd.yml) then drives on every push. Nothing here runs automatically — it is the
# documented, reviewable bring-up. Secrets are GENERATED here and stored in
# Secret Manager; they are never printed to logs or committed.
#
# Prereqs: gcloud authenticated as an owner of $PROJECT; billing enabled.
set -euo pipefail

: "${PROJECT:?export PROJECT=your-gcp-project}"
REGION="${REGION:-asia-east1}"          # HK-adjacent; matches the Alibaba HK intent
INSTANCE="${INSTANCE:-kap-demo}"        # Cloud SQL instance name
BUCKET="${BUCKET:-kap-demo-evidence}"
REDIS="${REDIS:-kap-demo-redis}"
GITHUB_REPO="${GITHUB_REPO:-Ivanwongclub/ka-playground}"  # owner/repo for WIF
gcloud config set project "$PROJECT"

echo "== 1. Enable APIs =="
gcloud services enable run.googleapis.com sqladmin.googleapis.com \
  artifactregistry.googleapis.com secretmanager.googleapis.com \
  redis.googleapis.com cloudscheduler.googleapis.com \
  iamcredentials.googleapis.com cloudbuild.googleapis.com

echo "== 2. Artifact Registry =="
gcloud artifacts repositories create kap --repository-format=docker \
  --location="$REGION" 2>/dev/null || echo "  (repo exists)"

echo "== 3. Cloud SQL Postgres 17 (RLS works: no superuser, NOBYPASSRLS roles) =="
gcloud sql instances create "$INSTANCE" --database-version=POSTGRES_17 \
  --tier=db-custom-1-3840 --region="$REGION" --storage-size=10 2>/dev/null \
  || echo "  (instance exists)"
gcloud sql databases create kap --instance="$INSTANCE" 2>/dev/null || echo "  (db exists)"
CONNNAME=$(gcloud sql instances describe "$INSTANCE" --format='value(connectionName)')
echo "  CLOUDSQL_INSTANCE = $CONNNAME"

echo "== 4. Secrets (generated once; the app NEVER connects as an owner) =="
mksecret() { # name value
  gcloud secrets describe "$1" >/dev/null 2>&1 \
    || printf '%s' "$2" | gcloud secrets create "$1" --data-file=- ; }
rand() { openssl rand -base64 24 | tr -d '/+=' ; }
APP_PW=$(rand); MIGRATE_PW=$(rand); SEED_PW=$(rand); GATE_CODE=$(rand)
mksecret kap-app-password    "$APP_PW"       # kap_app  (runtime, RLS-subject)
mksecret kap-migrate-password "$MIGRATE_PW"  # kap_migrate (owner, DDL only)
mksecret demo-seed-password  "$SEED_PW"      # strong demo login password (NOT 'password')
mksecret kap-demo-access-code "$GATE_CODE"   # shared front-door code (DemoGate). Rotate to a
# client-friendly value with: printf 'YourCode' | gcloud secrets versions add kap-demo-access-code --data-file=-
# The web service reads it as DEMO_ACCESS_CODE (secretKeyRef) — never hardcoded, never in the SPA bundle.
# APP_KEY: generate a Laravel key locally and store it.
if ! gcloud secrets describe kap-app-key >/dev/null 2>&1; then
  KEY=$(docker run --rm "$REGION-docker.pkg.dev/$PROJECT/kap/kap-api:bootstrap" \
        php artisan key:generate --show 2>/dev/null || echo "base64:$(openssl rand -base64 32)")
  printf '%s' "$KEY" | gcloud secrets create kap-app-key --data-file=-
fi

echo "== 5. GCS evidence bucket (uniform access, versioned) =="
gcloud storage buckets create "gs://$BUCKET" --location="$REGION" \
  --uniform-bucket-level-access 2>/dev/null || echo "  (bucket exists)"
gcloud storage buckets update "gs://$BUCKET" --versioning

echo "== 6. Memorystore Redis (Horizon queue) =="
gcloud redis instances create "$REDIS" --size=1 --region="$REGION" \
  --redis-version=redis_7_0 2>/dev/null || echo "  (redis exists)"
REDIS_IP=$(gcloud redis instances describe "$REDIS" --region="$REGION" --format='value(host)')
echo "  MEMORYSTORE_IP = $REDIS_IP"

echo "== 7. Deployer service account + Workload Identity Federation (no JSON keys) =="
SA="kap-deployer@$PROJECT.iam.gserviceaccount.com"
gcloud iam service-accounts create kap-deployer 2>/dev/null || echo "  (sa exists)"
for ROLE in run.admin cloudsql.client artifactregistry.writer \
            secretmanager.secretAccessor iam.serviceAccountUser \
            cloudscheduler.admin logging.viewer storage.admin; do
  gcloud projects add-iam-policy-binding "$PROJECT" \
    --member="serviceAccount:$SA" --role="roles/$ROLE" --condition=None -q >/dev/null
done
gcloud iam workload-identity-pools create github --location=global \
  --display-name="GitHub Actions" 2>/dev/null || echo "  (pool exists)"
gcloud iam workload-identity-pools providers create-oidc github-provider \
  --location=global --workload-identity-pool=github \
  --issuer-uri="https://token.actions.githubusercontent.com" \
  --attribute-mapping="google.subject=assertion.sub,attribute.repository=assertion.repository" \
  --attribute-condition="assertion.repository=='$GITHUB_REPO'" 2>/dev/null || echo "  (provider exists)"
POOL=$(gcloud iam workload-identity-pools describe github --location=global --format='value(name)')
gcloud iam service-accounts add-iam-policy-binding "$SA" \
  --role=roles/iam.workloadIdentityUser \
  --member="principalSet://iam.googleapis.com/$POOL/attribute.repository/$GITHUB_REPO" -q >/dev/null
echo "  GCP_WIF_PROVIDER = $POOL/providers/github-provider"
echo "  GCP_DEPLOYER_SA  = $SA"

echo "== 8. Roles + grants on the DB (THE fail-safe: app = non-owner, NOBYPASSRLS) =="
echo "  Connect via the proxy as the Cloud SQL admin and run, ONCE:"
echo "    ./cloud-sql-proxy --port 5432 $CONNNAME &"
echo "    PGPASSWORD=<admin> psql 'host=127.0.0.1 user=postgres dbname=kap' \\"
echo "      -v app_pw=\"\$(gcloud secrets versions access latest --secret=kap-app-password)\" \\"
echo "      -v migrate_pw=\"\$(gcloud secrets versions access latest --secret=kap-migrate-password)\" \\"
echo "      -f deploy/gcp/sql/01-roles-and-grants.sql"
echo "  (After the first migrate the CD's kap-grant job keeps grants current.)"

echo "== 8b. Pre-create kap-web with a PARKING revision (no-traffic-until-proof, first deploy) =="
# So the pipeline's deploy_candidate always finds a currently-serving revision to
# pin 100% to, keeping the real candidate at 0% until rls_proof passes — even on the
# very first deploy. The parking image (Google's hello) is superseded on first promote.
gcloud run deploy kap-web --image=us-docker.pkg.dev/cloudrun/container/hello \
  --region="$REGION" --port=8080 --no-allow-unauthenticated 2>/dev/null \
  || echo "  (kap-web already exists — leaving its current revision in place)"

echo "== 9. Cloud Scheduler → schedule:run every minute (readiness §4d) =="
echo "  Create ONE Cloud Run Job 'kap-schedule' (php artisan schedule:run), then:"
echo "    gcloud scheduler jobs create http kap-schedule --location=$REGION \\"
echo "      --schedule='* * * * *' --uri=<run-job-exec-uri> --http-method=POST \\"
echo "      --oauth-service-account-email=$SA"
echo "  Laravel's scheduler (routes/console.php) stays the source of truth:"
echo "  reconcile:run @03:00 HKT + aging/advancement/activation, sessions:advance /5min."

cat <<EOF

== 10. Set these GitHub repo VARIABLES (Settings → Actions → Variables) ==
  GCP_PROJECT       = $PROJECT
  GCP_REGION        = $REGION
  GCP_WIF_PROVIDER  = $POOL/providers/github-provider
  GCP_DEPLOYER_SA   = $SA
  CLOUDSQL_INSTANCE = $CONNNAME
  MEMORYSTORE_IP    = $REDIS_IP
  PUBLIC_URL        = https://<your-demo-domain>     # the public URL; NOT localhost

Provisioning done. cd.yml now drives every push to main through the gates.
EOF

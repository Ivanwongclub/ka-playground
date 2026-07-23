# AUDIT KAP-S00 — Foundation & kickoff

**Result:** IN PROGRESS · **Date:** started 2026-07-23 · **HEAD at gate:** `<pending — sprint not gated>`

> Written by Claude Code at the sprint's end. Honesty outranks looking good — a documented FAIL is
> worth more than an untrue PASS. This is the BUILD audit; the in-product audit element is separate.
>
> Opened early (STEP 1, 2026-07-23) on Leo's instruction to record the STEP 0 deviation resolutions
> while they are fresh. Sections are filled as steps complete; the gate verdict comes last.

## 1. Files changed
| Path | A/M/D | Why |
|------|-------|-----|
| `build-reference/assets/**` (33 files) | A | STEP 0 — MVP imagery rescued from Supabase Storage |
| `docs/design/ASSET-MANIFEST.md` | M | §2 `.env` path corrected to `build-reference/.env` (authorised by Leo, commit `KAP-S00-0a`) |
| `docs/requirements/REGISTER.md` | M | STEP 1 — FR/SR/GR/OR IDs assigned from Spec v4.2; amendment map added |
| `docs/sprints/S00/AUDIT.md` | A | This file, opened early per Leo |

## 2. Step-by-step verification (real output, pasted)

### STEP 0 — Asset rescue · commit `da47bf3` (+ doc fix `4e2f0c0`)
```
$ bash rescue-mvp-assets.sh          # ASSET-MANIFEST §2, .env path corrected
OK  scheme-images/cards/card-sc1.jpg
... (32 OK lines for scheme-images, zero MISS lines)
OK  auth-assets/featured-sc5.jpg     # fetched directly, see deviation D2
--- inventory ---
33 files
$ find build-reference/assets -type f | wc -l
      33
$ find build-reference/assets -type f -exec stat -f%z {} + | paste -sd+ - | bc
2876664
```
All 33 files verified as genuine JPEG data with `file(1)` — no error bodies saved as images.
**Independent verification (Leo, 2026-07-23):** a service-role inventory of the Supabase project
returned exactly 33 objects totalling 2,876,664 bytes across the two public buckets
(`auth-assets`, `scheme-images`) — matching the rescued tree byte-for-byte.
Result: PASS

### STEP 1 — Requirement register · commit `76df198`
```
$ grep -c '^| FR' docs/requirements/REGISTER.md
67
```
GR004–GR007, SR004–SR018, FR001–FR067, OR001–OR003 assigned; amendment map 2.1–2.27 complete.
Result: PASS

### STEP 2 — Scaffold + theme · commit (this step)
```
$ php artisan --version
Laravel Framework 12.64.0
$ cd api && php artisan test
  Tests:    2 passed (2 assertions)
$ cd web && node scripts/i18n-check.mjs
OK   en.json — 86 keys, parity complete
OK   zh-TC.json — 86 keys, parity complete
OK   zh-SC.json — 86 keys, parity complete
i18n:check PASSED — parity complete, no hardcoded user-facing strings
$ npx tsc -b            # clean, no output
$ npm run build         # ✓ built in 1.55s (chunk-size warning — see §5)
$ docker compose config -q && docker compose up -d --build
kap-app-1 / kap-horizon-1 / kap-nginx-1 / kap-postgres-1 / kap-redis-1 — all (healthy)
$ curl -s -o /dev/null -w 'HTTP %{http_code}' http://localhost:8080/up
HTTP 200
# Headless-Chromium locale cycle (Playwright, scratchpad-only tooling):
EN: style-guide rendered · 繁體中文: nav = 總覽 · 简体中文: nav = 总览 · back to English
console lines: 0 · MISSING KEY: 0 · errors: 0 → LOCALE CYCLE VERIFY: PASS
```
Screenshots verified by eye: dark-only shell, gold-active sidebar, palette,
all §9 component variants, themed toast + confirm (App.useApp — no default blue),
gold line chart + Viridis heatmap on kaChartTheme, TC sample at 1.8 line-height.
Result: PASS

### STEP 3 — Audit spine · commit (this step)
```
$ php artisan test
   PASS  Tests\Feature\AuditSpineTest
  ✓ update on audit events fails at the database
  ✓ delete on audit events fails at the database
  ✓ model layer also refuses updates
  ✓ service writes event carrying actor identity
  ✓ auth event types are reserved per 2 11
  Tests:    7 passed (9 assertions)

$ docker compose exec postgres psql -U kap -d kap -c "UPDATE audit_events SET action='tampered';"
ERROR:  audit_events is INSERT-only (BI-1): UPDATE blocked
CONTEXT:  PL/pgSQL function audit_events_immutable() line 3 at RAISE
# DELETE and TRUNCATE rejected identically (statement-level trigger — fires even on 0 rows)

$ tinker: AuditService->record(...) as synthetic user →
written: 019f8f94-6375-72c7-bcd9-da69c9c58541
psql SELECT → event row visible with actor_id 1, action audit_spine.smoke
```
Result: PASS

### STEP 4 — Shared upload service · commit (this step)
```
$ php artisan test
   PASS  Tests\Feature\ClamAvIntegrationTest
  ✓ real clamd flags eicar · ✓ real clamd passes clean content
   PASS  Tests\Feature\UploadServiceTest
  ✓ disallowed mime is rejected · ✓ oversize file is rejected
  ✓ unknown context is refused · ✓ image reencode strips embedded metadata
  ✓ clean file is invisible until scan passes · ✓ eicar is quarantined with audit event
  Tests:    16 passed (36 assertions)

# End-to-end on the compose stack (Horizon + real clamd):
layer1 intake rejection: {"file":["File type text/plain is not accepted here"]}
 document | quarantined | Eicar-Test-Signature | uploads/quarantine/0
 image    | clean       |                      | uploads/clean/019f8f
 upload.quarantined | pending | quarantined | ClamAV hit: Eicar-Test-Signature
[laravel.log] local.CRITICAL: Upload quarantined by ClamAV {"upload_id":"019f8fc1-…","signature":"Eicar-Test-Signature"}
clean image: intake → pending (visible=false) → Horizon scan → clean (visible)
```
Note: EICAR is by design only detectable at byte 0 of a file, and a raw EICAR file
finfo-types as text/plain — which the MIME allow-list rejects before any scan. The
VERIFY therefore demonstrates both layers separately: intake rejection of the raw
file, and queued-scan quarantine of EICAR bytes in the pending store.
Result: PASS

### STEP 4 addendum — full-path verification (Leo review, 24 Jul)
Gap found in review: the two layers were only demonstrated separately; the
production case — a file that PASSES the allow-list and is malicious — was
untested. Fix in two parts:
1. **Pipeline reorder:** the scan now runs on ORIGINAL bytes; images are
   hardened (re-encoded) only after a clean verdict. Previously re-encode ran
   first, so a metadata-borne signature would be neutralised without ever being
   detected or alerted. Hardening failure on a clean file → quarantined, never
   published.
2. **Test-environment signature** `deploy/clamav/kap-test.ndb` (harmless marker
   string) mounted into the compose clamav — local/test only, never in the
   staging/production compose variants.
```
$ php artisan test
   PASS  Tests\Feature\ClamAvIntegrationTest
  ✓ valid pdf with marker passes intake and is quarantined by scan     0.06s
  ✓ valid jpeg with marker passes intake and is quarantined by scan    0.03s
   PASS  Tests\Feature\UploadServiceTest
  ✓ scan sees original bytes and clean image is hardened
  ✓ undecodable image is quarantined not published
  Tests:    19 passed (52 assertions)
```
Both full-path tests assert: intake accepts (status pending, correct MIME) →
real clamd flags `KAP.TestSig.Marker.UNOFFICIAL` → quarantined + audit event.
Result: PASS

## 3. Assertions registered this sprint
| Assertion | Tag | First green run output pasted? |
|-----------|-----|-------------------------------|
| (audit immutability probe — due in STEP 5) | S00 | pending |

## 4. Deviations from SPRINT.md
| # | Card said | Actually happened | Why | Status |
|---|-----------|-------------------|-----|--------|
| D1 | Run the rescue script in ASSET-MANIFEST §2 (sources `./build-reference/mvp/.env`) | Script run with source path `./build-reference/.env` | The MVP root **is** `build-reference/`; no `mvp/` subdirectory exists. Only the path changed; script otherwise verbatim. Manifest itself corrected in `KAP-S00-0a` on Leo's authorisation | **Resolved** |
| D2 | Script lists each folder via the Storage API "catching unknown announcement slugs" | `auth-assets` listing returned `[]` under the anon key; `featured-sc5.jpg` (the file the manifest names) was fetched directly instead. Risk flagged: unlisted extra files in that bucket would not have been rescued | **Resolved 2026-07-23** — Leo's service-role inventory confirmed exactly 33 objects / 2,876,664 bytes across both buckets, matching the rescued tree byte-for-byte. The empty listing was a permissions artifact of the anon key, not a missing file. Nothing was left behind |
| D3 | Theme per DESIGN-SYSTEM.md §5 verbatim | Leo's design-token review (23 Jul) found three WCAG 1.4.11 non-text failures **in the doc's own values** (border 1.18:1, focus outline 2.06:1 blended, language accent 4.04:1 as text) plus three §5 omissions vs §3.1/§4 (colorTextSecondary, type scale, borderRadiusSM). All values confirmed doc-verbatim before changing anything | **Resolved 2026-07-23, Leo-authorised** — border split into `colorBorder #726889` (controls) / `colorBorderSecondary #2A2235` (decorative); `controlOutline` solid gold; accents unchanged with a never-as-body-text rule for Language (other four measured passing by Leo); §3.1/§4 omissions implemented. DESIGN-SYSTEM.md §3.1/§3.3/§5/§11.5 patched to match (authorised) so code and doc cannot diverge |

## 5. Leftovers & newly discovered risks  ← input to the next card's adjustment
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | `hero-tiles` holds only sc1/sc3/sc5 and `featured` only sc5 — consistent with manifest §4; the §12 gradient fallback must cover the empty slots | Low | S00 STEP 6 (fallback wiring) / S02 (catalogue) |
| 2 | Web bundle is one 2.9 MB chunk (charts lib dominates) — route-level code-splitting would fix; not in the S00 card | Low | S01+ (when routes multiply) |
| 3 | PWA manifest references `/assets/icons/icon-192.png` / `icon-512.png` — files generated from the rescued logo/favicon in STEP 6 | Low | S00 STEP 6 |
| 4 | Approved unnamed deps (Leo, this session): react-router-dom, i18next + react-i18next, @fontsource self-hosted fonts, @ant-design/v5-patch-for-react-19. Playwright used for VERIFY lives in the scratchpad only — not a project dependency | Info | — |
| 5 | **STEP 5 MUST register (Leo, 23 Jul):** nightly assertion that the BI-1 trigger is still ENABLED (`pg_trigger.tgenabled = 'O'` for `audit_events_immutable_guard`) — a disabled trigger is a silent breach. Phpunit already guards it; the nightly probe repeats it | High | S00 STEP 5 |
| 6 | Test suite moved SQLite → Postgres (Leo, 23 Jul): `kap_test` DB in the compose postgres, host port 54329, phpunit env updated. SQLite trigger branch kept in the migration (costs nothing; any future sqlite use inherits enforcement). For an already-initialised pg volume, `CREATE DATABASE kap_test OWNER kap;` — the init script only runs on fresh volumes. CI (STEP 6) needs a pg service for tests | Info | S00 STEP 6 (CI) |
| 7 | **actor_role permanence:** `audit_events` is immutable, so rows written before S01 wires the role model carry `actor_role = NULL` forever — they cannot be backfilled. Confirmed: every row written so far is synthetic (factory users, test data); no non-synthetic data will be written before S01. No production deploy exists | Info | S01 wires actor_role |

## 6. Exit gate
```
(pending — sprint in progress)
```
**Verdict:** pending.

## 7. Invariant check
| BI | Touched? | Evidence (test/assertion name) |
|----|----------|-------------------------------|
| (none yet — STEPS 0–1 were assets and documentation only) | | |

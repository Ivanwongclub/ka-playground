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

## 3. Assertions registered this sprint
| Assertion | Tag | First green run output pasted? |
|-----------|-----|-------------------------------|
| (audit immutability probe — due in STEP 5) | S00 | pending |

## 4. Deviations from SPRINT.md
| # | Card said | Actually happened | Why | Status |
|---|-----------|-------------------|-----|--------|
| D1 | Run the rescue script in ASSET-MANIFEST §2 (sources `./build-reference/mvp/.env`) | Script run with source path `./build-reference/.env` | The MVP root **is** `build-reference/`; no `mvp/` subdirectory exists. Only the path changed; script otherwise verbatim. Manifest itself corrected in `KAP-S00-0a` on Leo's authorisation | **Resolved** |
| D2 | Script lists each folder via the Storage API "catching unknown announcement slugs" | `auth-assets` listing returned `[]` under the anon key; `featured-sc5.jpg` (the file the manifest names) was fetched directly instead. Risk flagged: unlisted extra files in that bucket would not have been rescued | **Resolved 2026-07-23** — Leo's service-role inventory confirmed exactly 33 objects / 2,876,664 bytes across both buckets, matching the rescued tree byte-for-byte. The empty listing was a permissions artifact of the anon key, not a missing file. Nothing was left behind |

## 5. Leftovers & newly discovered risks  ← input to the next card's adjustment
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | `hero-tiles` holds only sc1/sc3/sc5 and `featured` only sc5 — consistent with manifest §4; the §12 gradient fallback must cover the empty slots | Low | S00 STEP 6 (fallback wiring) / S02 (catalogue) |

## 6. Exit gate
```
(pending — sprint in progress)
```
**Verdict:** pending.

## 7. Invariant check
| BI | Touched? | Evidence (test/assertion name) |
|----|----------|-------------------------------|
| (none yet — STEPS 0–1 were assets and documentation only) | | |

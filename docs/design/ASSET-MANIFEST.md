# ASSET-MANIFEST — MVP Logos & Images into the New Build

**Place in:** `ka-build/` · **Consumed by:** Sprint S00 (its definition of done now includes this manifest fully executed)
**Why this exists:** the MVP bundles only 2 files; every other image lives in the Lovable project's Supabase Storage. Those buckets are the single copy — if the Lovable project is deleted, the imagery is lost. Step 1 below rescues them.

---

## 1. What exists where

### 1.1 Bundled in the MVP codebase (2 files — already secured alongside this manifest)
| File | MVP path | Use in new build |
|---|---|---|
| `armour-academy-logo.webp` (30 KB) | `src/assets/` | Login page logo. Design System §11.1 applies: light/mono variant on aubergine surfaces; this webp is the interim mark until client colour vectors arrive (OD open decision). |
| `favicon.ico` (41 KB) | `public/` | Favicon, carried over as-is. |

### 1.2 Supabase Storage (the real asset library)
Project: `jywgngpiuqcqgowngxuk.supabase.co` — credentials in the MVP `.env`.

| Bucket / folder | Naming convention | Used for (new build destination) |
|---|---|---|
| `scheme-images/cards/` | `card-{sc1..sc5}.jpg` | Programme catalogue card posters → `public/assets/programmes/cards/` |
| `scheme-images/heroes/` | `hero-{id}.jpg` | Programme detail hero banners → `.../heroes/` |
| `scheme-images/hero-tiles/` | `hero-tile-{id}.jpg` | Landing hero collage → `.../hero-tiles/` |
| `scheme-images/featured/` | `featured-{id}.jpg` | Featured showcase → `.../featured/` |
| `scheme-images/galleries/{id}/` | `gallery-{id}-{1..3}.jpg` | Detail gallery tabs → `.../galleries/{id}/` |
| `scheme-images/announcements/` | `{slug}.jpg` (slugs vary — must be listed, not guessed) | News/announcement cards → `.../announcements/` |
| `auth-assets/` | `featured-sc5.jpg` | Login split-screen background → `public/assets/auth/` |

Known scheme ids: `sc1 sc2 sc3 sc4 sc5`.

---

## 2. Rescue script (run at S00 kickoff, before anything else)

Run from the repo root on a machine with open network (Claude Code environment or Leo's laptop). It lists each folder via the Storage API (catching unknown announcement slugs) and downloads everything into `build-reference/assets/`.

```bash
#!/usr/bin/env bash
# rescue-mvp-assets.sh — pull all imagery out of the MVP's Supabase Storage
set -euo pipefail
source ./build-reference/mvp/.env   # SUPABASE_URL + VITE_SUPABASE_PUBLISHABLE_KEY
KEY="$VITE_SUPABASE_PUBLISHABLE_KEY"; BASE="$SUPABASE_URL/storage/v1"
OUT="build-reference/assets"; mkdir -p "$OUT"

fetch_folder () {  # $1=bucket $2=prefix
  curl -s "$BASE/object/list/$1" -H "apikey: $KEY" -H "Authorization: Bearer $KEY" \
    -H "Content-Type: application/json" \
    -d "{\"prefix\":\"$2\",\"limit\":200}" |
  python3 -c "import sys,json;[print(o['name']) for o in json.load(sys.stdin) if o.get('id')]" |
  while read -r f; do
    mkdir -p "$OUT/$1/$2"
    curl -sf "$BASE/object/public/$1/$2$f" -o "$OUT/$1/$2$f" \
      && echo "OK  $1/$2$f" || echo "MISS $1/$2$f"
  done
}

for d in cards/ heroes/ hero-tiles/ featured/ announcements/; do fetch_folder scheme-images "$d"; done
for id in sc1 sc2 sc3 sc4 sc5; do fetch_folder scheme-images "galleries/$id/"; done
fetch_folder auth-assets ""
echo "--- inventory ---"; find "$OUT" -type f | sort; find "$OUT" -type f | wc -l
```

Commit the downloaded `build-reference/assets/` tree to the repo immediately — from that commit onward the build no longer depends on the Lovable project existing.

---

## 3. Utilisation rules in the new build

1. **S00:** logo + favicon wired into ProLayout logo slot and `index.html`; login background from `auth-assets`.
2. **S02 (programme config):** seeded programmes reference the rescued card/hero/gallery images so the catalogue is visually real from the first demo — no gradient placeholders where an MVP image exists.
3. **Serving:** Phase 1 serves from `public/assets/` (Vite static); the S00 card's OSS task later uploads the same tree to the OSS media bucket and swaps the base URL via one env var (`ASSET_BASE_URL`) — paths identical, no code change.
4. **Naming is preserved** (`card-sc1.jpg` etc.) so the Image Prompts v2 upgrade path still applies: any future client-supplied image replaces a file of the same name, nothing else moves.
5. **Gap check:** any slot with no rescued image (e.g. missing gallery-sc3-2) falls back to the category-colour gradient per the design system — never a broken image.


---

## 4. Screen-to-asset mapping (verified against MVP screenshots, 23 Jul)

### Login page
| Visible element | Source | Status |
|---|---|---|
| Gold "AA — Armour Academy · Skills in Action" logo (top-left) | `src/assets/armour-academy-logo.webp` | **Secured** (bundled) |
| Full-bleed workshop photo (students + model F1 car) | `auth-assets/featured-sc5.jpg` | Rescue script §2 |
| Quote overlay ("My son built a model F1 car…" — Mrs. Yip) | Text, not an asset | Carry copy into S01 login |
| Role chips (Admin/School/Teacher/Parent), gold CTA, social buttons | Components, not assets | S01 rebuilds per design system |

### Playground landing
| Visible element | Source | Status |
|---|---|---|
| "KA" circular gold mark in sidebar | **Styled component, not an image** — text on gold circle | Rebuilt in code (already in style guide) |
| STEM on Car tall hero tile | `scheme-images/hero-tiles/hero-tile-sc5.jpg` | Rescue script |
| Cambridge English Online tile | `scheme-images/hero-tiles/hero-tile-sc1.jpg` | Rescue script |
| Creative Arts Studio tile | `scheme-images/hero-tiles/hero-tile-sc3.jpg` | Rescue script |
| Category tags (STEM on Car / Language / Arts) | Components using category accent colours | Rebuilt |
| KPI stats row (5 · 135 · 94% · 4.8/5) | Data, not assets | Real aggregates in the new build |

### Design patterns worth carrying over (S01/S02 reference)
The hero headline pattern — white Montserrat with **one gold accent word** ("finds their **spark**") — the duotone photo treatment, the gold CTA + ghost secondary pairing, and the login role-chip selector all match the documented system and should be reproduced as-is.

### Two caveats these screens surface
1. **Copy vs positioning.** "Built for Hong Kong learners aged 6 to 19", public "Request access", social sign-in on the login, and "Family satisfaction" read as mass-market. The confirmed positioning (Global Elite Summer Program, invitation-only, spec L3–L4) means the *visuals* carry over but this copy does not — S01 copy comes from the spec's register (understated, invitation-led), and social sign-in binds to invitation tokens only (B10).
2. **Two marks in play.** The gold "AA Armour Academy" logo is the education-facing mark; "KA / Kings Armour" is the holding brand. Both appear in the MVP. Until the client supplies brand guidance (OD-3), the rule: **AA logo on user-facing surfaces (login, certificates), KA mark in the platform shell** — matching what the MVP already does.

**S00 definition-of-done addition:** `rescue-mvp-assets.sh` run, tree committed, inventory count recorded in AUDIT.md, logo/favicon/login-bg rendering in the style-guide route.

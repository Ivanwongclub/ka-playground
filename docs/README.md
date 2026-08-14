# KAP — Build & Design Documentation

Authoritative documents for building KA Playground to full function. Read this first.

## Which doc is authoritative for what

**`build/KAP-Full-Function-Build-Plan.md`** — THE operative plan. The build sequence (Phase 0 → 3), the keep-backend/rebuild-UI ruling, and the recommended order. Start here. This supersedes any earlier "demo v1" scoping.

**`build/KAP-Consolidated-Build-Backlog.md`** — the card-level detail behind the plan's phases: every card, its review tier ([CS]/[FIN]/[RLS]/[UI]/[CFG]), its source, and the four mandatory build conditions. Use for card content; the plan governs order.

**`build/KAP-D4-Decision-Sheet.md`** — owner rulings on the five open product questions (merge, waivers, period lock, rubric/moderation, school reports). Phase-3 cards depend on these.

**`reference/KAP-Data-Model-and-Schema.md`** — the authoritative data-model + RLS reference. The source of truth for who-can-see-what and every immovable. When a card touches access control, this governs.

**`reference/KAP-Schema-Raw-Columns.txt`** — literal column-by-column schema (all 58 migrations) for depth.

**`design/KAP-Design-Kit-DS2.md`** — DS2 v3: the token set (`theme.ts` + `tokens.css` shape), AntD component config, and component specs. THE visual contract. Built in Phase 0.

**`design/KAP-UIUX-Proposal.md`** — the IA + record-page compositions per persona. The structural design spec. NOTE: where the body disagrees with its 14-Aug "RULED" block, the RULED block wins.

**`design/KAP-Prototype.html`** — the clickable visual reference. A build card matches the prototype's composition for that screen. Reference only — never copy its markup; rebuild in React/AntD to the DS2 v3 kit.

## The immovables (never violated by any card)

Guardian-only consent signing · four-eyes on money (recorder ≠ confirmer; refunds platform-gated) · formed-team membership platform-only · cross-family/cross-school isolation · assessment embargo (family sees a result only after release) · entitlement-iff rendering (a card/count/name exists iff the viewer's read returns it; unentitled = absent, deep links 404).

## The standing review gate (every card)

map → ruling → HELD build → line-by-line diff review → registered elevations char-matched to `config/scope-elevations.php` → RLS proof (RIDER-1 per seeded role) → behaviour-sha on untouched arms → reconcile 58/58 → push.

## Precedence

Where any two docs disagree: the build plan wins on sequence; the data-model/RLS reference wins on access control; the design kit + prototype win on presentation; a doc's own "RULED"/addendum block wins over its body.

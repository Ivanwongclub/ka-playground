# KAP — PROTOTYPE WORKFLOW & UIUX
### What `docs/design/KAP-Prototype.html` actually specifies, screen by screen
**Document 2 of 3** · 20 Aug 2026 · derived solely from the HTML (387,150 B / 57 screens / 42 `data-p` blocks); line numbers cite the file. Harness elements (not product) are marked ⛔.

**Framing:** family personas render in a **phone frame 390×844** (`.pf`/`.notch`, single column); staff personas render **desktop 1440×900** (`pagewrap`). One chrome for all (header L389 + sider per persona); only items and footer differ. The `fam` class is a modifier, not a second shell. The top persona `.bar` is the prototype's own switcher ⛔.

---

## 0 · THE CHROME (one shell, all personas) — L389–419
- **Header (58px, transparent + blur 18px + hairline):** brand cell = sider width · elastic search (icon + input + `.sdrop` typeahead, no fill, 360px) · bell `.hico` + warn `.badge` → notification drawer · `.hdr-sep` · user chip (avatar + name + role + caret) → menu: Profile · Settings · hr · Log out (danger).
- **Sider (250px / 58px collapsed):** floating 36px collapse circle at `side-w−18` with tooltip · FLAT item list (`.ico` 19px + `.lbl` + optional `.nbadge` count pill, orange 18% tint; collapses to an 8px dot) · footer: **family** = "Last sign-in {dt}" + session count-up + sign-out; **staff** = env dot + version + count-up + sign-out.
- Already ruled where the prototype is stale (Kit wins): font Inter/Noto, radii 10/6, canvas `#0F0D14`, surfaces `#191521`, sh-1/sh-2 shadows, focus ring borderStrong (never gold), **no `.dot` inside chips**, real build sha over "v1.0 · UAT".

**Nav per persona (label · badge if shown):**
| Persona | Items |
|---|---|
| Student (4) | Home · Programmes · Market Place · Me |
| Guardian (6) | Home · Children · Consents ①· Payments ① · **Requests ①** · Me |
| Mentor (3) | My Teams · My Sessions · Grading ① |
| School (8) | Overview · Requests ① · Students · Teams · Team Formation · Sessions · Consents ③ · Billing ① |
| Ops (13) | Approvals ③ · Team Formation ① · Consent ops ② · Withdrawals ① · Students · Guardians · Enrolments · Teams · Schools · Programmes · Sessions · Assessments · Settings |
| Finance (6) | To record ② · To confirm ① · Orders & receipts · Refunds ① · Invoice aging · Reconciliation |
| Audit (2) | Audit explorer · Consent evidence |

⚠️ Build note: the guardian's **Requests** slot (withdrawals/changes) was NOT in C1-SHELL's 5-slot ruling — the prototype has 6. Alignment item for Doc 3.

---

## 1 · STUDENT (6 screens, phone)

**stu-home L422** — greeting eyebrow ("GOOD AFTERNOON / Ka-yan") on photo hero · **NEXT UP** card: session title · programme+venue · date/time + `Booked` chip · **My programmes**: per-enrolment banner cards, 5-seg segbar (Submitted·Pool·Teamed·Confirmed·Active), one status line + ONE action (Confirmed→chip · in-pool→"Waiting for your guardian" + `Remind`).
**stu-progs L448** — the enrolment-card list (banner · segbar · state-dependent rows: confirmed = Team/Next session/Tracker/Results; in-pool = Consent/Team("Not yet · 3 teams forming"+gold `Find a team`)/Next session). Rows are value-present, not state-switched.
**stu-space L470** — THE scoped space: band header (banner, title, term, status) + 5 sub-tabs:
- **Journey**: 3 stat tiles (Team / Next session / Consent, ghost cards) → gold stepper with dated knots → "What happens next" tile.
- **Team** `t-team`: teamed = roster (initials av + name + role, "(you)"), Mentor plain-name, "Join requests · you are Team Lead" (Accept gold/Decline); in-pool = "No team yet · In the pool" + `Join with code` + gold `Create a team` + lobby wall ("Led by {name} · Public" + `Request to join`).
- **Sessions**: rows title · date+venue · Booked/`Book` (gold), Materials link.
- **Tracker** `t-track` L559: header + "Learn · 1 of 4 met" chip · 5 gate pills (done ✓ / now / locked) · gate-detail: chip + "Passed 12 Jul by the academy" line + requirement rows (title + evidence line + Met/Pending chip + `Submit` on team deliverables). GATES model at L2412.
- **Results** `t-res` L564: per assessment — pre-release: pend chip "In progress — results not yet released" + notify line; released: ok chip + **34px gold score** "/100" + "Released {date}". ⛔ "Demo control / Simulate release" card.
**stu-explore L583 → stu-progdet L620** — marketplace cards (banner, blurb, fee, window) → detail: hero, About, schedule preview, fee, gold `Ask my guardian to enrol me` (interest ping, not an enrolment).
**stu-me L661** — profile identity, school, language, **"My guardians"** list, sign-out. (D-7: guardians list is an ungranted read as-built.)

**Student workflows encoded:** browse→interest ping→(guardian enrols)→consent-wait (`Remind`)→pool→find/create/join team→submit→confirmed→book sessions→attend→tracker requirements→released results. Every gap between these steps is a chip + one action on the card where it matters.

---

## 2 · GUARDIAN (9 screens, phone)

**gua-home L701** — hero "Your family's pending actions" · action cards EACH child×need: consent → gold `Review & sign`; order → amount · due · blue countdown chip · gold `View payments` · stat trio (Children / Outstanding HK$ / Enrolments).
**gua-children L730** — child cards: avatar+name, "{school} · {n} enrolments", per-enrolment rows (Consent "Expires in 6 days" chip / Fees "HK$1,800 · due 20 Aug" / status "Confirmed · Team Aurora" / Next session), whole card → child; trailing **"Enrol a child"** gold card.
**gua-child L750** — one child: identity, school, per-enrolment cards → gua-space.
**gua-space L777** — the SAME scoped space; guardian mirror: L812 states Team/Sessions/Tracker/Results "composed identically to Ka-yan's own view… the guardian additions are **Fees and signing authority**." Fees tab: order lines, amount, receipt links.
**gua-consents L818** — list: programme · child · state chip · countdown → **gua-sign L839, the ceremony:** "‹ Consents" back · "6 days" chip · "For {child} · rendered in English · v3.2" · numbered consent text (participation / health / media / data / withdrawal) · "— End of consent text —" · **"Signing is locked — read to the end first"** scroll gate · affirmation checkbox · **"Media consent — optional"** separate `Allow` toggle ("Declining does not affect participation") · signature pad ("Tap to sign") · gold `Sign consent` · "recorded immutably with the exact version you read."
**gua-pay L866** — order cards: child·programme, line items, total, due, `Get payment link` / paid+receipt; **"I've paid — submit reference"** (⚠️ D-6: as-designed B-17 grants remittance declaration to the SCHOOL — unruled divergence).
**gua-reqs L890** — **Requests**: withdrawal/change requests list, state chips (pending endorsement / with academy / decided), `New request`. (As-built: API only.)
**gua-me L905** — identity, language, notification prefs, **"Link another child"** (pairing ceremony entry), sign-out.

**Guardian workflows encoded:** everything actionable surfaces on Home the moment it exists; sign ceremony = scroll→affirm→(optional media)→signature→immutable record; pay = link out; requests = submit + track states; linking = ceremony from Me.

---

## 3 · MENTOR (6 screens, desktop)

**men-teams L942** — team cards (programme, stage chip, members, next session) → **men-team L960**: roster+roles, stage tracker, consent-status column (status only), check-in notes area, gate `Approve` where authorised.
**men-sess L992 → men-sessdet L1004** — sessions list → detail: roster with per-student attendance toggles (present/absent/late), materials, save.
**men-comp L1026** — mentor's read of a forming team (flag-gated).
**men-grade L1058** — Grading ①: submissions queue → score entry + comment → submit grade (release stays academy-side).

---

## 4 · SCHOOL ADMIN (10 screens, desktop)

**sch-over L1093** — stat row (roll, active enrolments, open consents, invoices due) + attention list.
**sch-students L1116 → sch-student L1129** — roll table → student record (identity, enrolments, consent state, guardian links, notes).
**sch-reqs L1158** — Requests ①: withdrawal endorsements queue (pastoral, non-authoritative) + vouches.
**sch-comp L1173** — Team Nova composition (school's read of forming teams in its lobby: pool list with consent flags + roster).
**sch-board L1205** — the school's Formation board (its lobbies only).
**sch-teams L1234** — teams table w/ stage chips.
**sch-sess L1256** — sessions for its students.
**sch-chase L1267** — Consents ③: the CHASE queue — outstanding consents per family, expiry countdown, `Send reminder` (never sign).
**sch-bill L1289** — Billing ①: consolidated invoices, line = student×programme, aging state, `Mark remitted` declaration (B-17 — the school-side counterpart of D-6).

---

## 5 · OPS (18 screens, desktop) — the deepest persona

**ops-appr L1353** — Approvals ③: registration / guardian-link / vouch queues, oldest-first, approve/reject with consequence copy.
**ops-board L1366** — **Team Formation**: occupancy header per programme (In pool 14 · Forming 3 · Submitted 1 · Confirmed 6 · lobby + freshness line) · programme table (window, counts, Attention) → **ops-comp L1439**: "Team Nova — composition": `Edit team`/`Cancel`/`Save changes` · left = pool list (name, form, consent chip, "+11 more filtered") · right = roster n-of-max with roles — drag-to-compose, then confirm ceremony (consequence copy: seats+money fire).
**ops-consops L1471** — Consent ops ②: templates/versions, re-consent fan-out, void.
**ops-wd L1483** — Withdrawals ①: decide queue with refund-window band evidence.
**ops-students / ops-stu L1495/1507** — Student 360: identity, guardian links, enrolments, teams, consents, orders, audit strip.
**ops-guardians L1551** — guardian directory + link states.
**ops-enrs / ops-enr L1562/1581** — Enrolments-as-record: filterable table → enrolment record (journey, consent, order, team, session bookings).
**ops-teams / ops-team L1616/1628** — teams table → Team 360 (roster, tenure ledger, gates, teacher links).
**ops-schools L1657** — schools + delegation summary.
**ops-progs / ops-prog L1668/1679** — programme list → Programme 360 (versions, fees, capacity, lobbies, sessions, assessments links).
**ops-sess L1715** — session admin (lifecycle, capacity, roster export).
**ops-assess L1731** — assessments: state machine incl. **`Release results` (danger, "families notified")** L1997.
**ops-config L1747** — Settings: wizard, fee items, withdrawal bands, consent templates, categories, certification/badge rules.

---

## 6 · FINANCE (6 screens, desktop)

**fin-rec L1788** — To record ②: incoming manual payments → record (recorder leg).
**fin-conf L1800** — To confirm ①: BI-9 confirm queue; own-recorded rows show "You" + disabled Confirm + reason.
**fin-orders L1826** — orders & receipts explorer (line items, receipt chain).
**fin-refunds L1839** — Refunds ①: approve → second-officer confirm; credit notes.
**fin-aging L1851** — consolidated invoice aging (school-settled), exception rows.
**fin-recon L1871** — reconciliation: nightly result + manual reconcile action, assertion list.

---

## 7 · AUDIT (2 screens, desktop)

**aud-log L1903** — Audit explorer: filterable immutable trail, before/after images, zero write affordance.
**aud-consent L1932** — Consent evidence: per-consent certificate view (hashes, version, signer, timeline).

---

## 8 · CROSS-CUTTING UIUX GRAMMAR THE PROTOTYPE ENCODES

1. **Action-first families:** a family screen is a list of "the next thing" cards — every pending obligation renders where the person already is, with ONE gold action. Browse depth exists only behind the scoped space.
2. **The scoped space is the product's centre:** student, guardian (mirror), and ops (record) all view the SAME enrolment object in their own grammar — D-1's one-object-two-renderers, drawn.
3. **Queues are the staff grammar:** every staff persona opens on its queue(s); badges carry the count; rows carry the evidence beside the decision (consent chips in the composer, refund window in withdrawals, "You" in BI-9).
4. **Chips = state, gold = the one action, evidence lines = muted sub-text.** Countdown chips are blue; warnings orange; danger red; released/score is the sole value-gold.
5. **Ceremonies are full screens** (consent sign, formation confirm, release) with consequence copy and irreversibility stated at the button.
6. **Honest emptiness:** pre-team, pre-release, pre-booking states name the truth in one line, never instructions.

## 8A · THE INTERACTION LAYER — sub-navigation, modals, drawers, controls (complete inventory)

### Sub-tab strips (`.subtab`) — the only in-page tab navigations in the file
| Where | Tabs | Line |
|---|---|---|
| stu-space | Journey (default) · Team · Sessions · Tracker · Results | L482–485 |
| gua-space | Journey · **Fees** (`g-fees`) · **"Team · Sessions · Tracker · Results"** (`g-mirror` — one combined tab whose panel L812 states the mirror rule) | L785–786 |
| ops-board | programme filter as subtabs: All · Summer Venture · JC InnoTech | L1372 |
| ops-sess / ops-assess | same programme-filter subtab pattern | L1718 / L1734 |

Staff record pages (ops-stu, ops-enr, ops-team, ops-prog, sch-student) have **NO inner tab strips** — they are single-scroll compositions of `.eyebrow`-titled zones (e.g. ops-stu: Student · School · Guardian link · Next session · enrolments · orders; ops-enr: the enrolment record zones). Detail-on-demand is by zone, not by tab.

### Segment bar (`.segbar`) — display component, not navigation
5 pills (Submitted · Pool · Teamed · Confirmed · Active), states `d`(done)/`c`(current)/todo. Appears on: stu-home cards ×2, stu-progs ×2, gua-child ×2. Never clickable.

### Modals (12 `modal-wrap` blocks, L1970–2140) — every ceremony/confirm in the product
| id | Title | Persona · trigger | Consequence copy pattern |
|---|---|---|---|
| mConfirmTeam | "Confirm Team Meridian?" | ops composer | seats + money fire; irreversible |
| mCompSave | "Save team changes?" | ops composer edit | roster delta listed |
| mGate | "Pass the Learn gate for Team Aurora?" | mentor/ops tracker | approver authority stated |
| mRelease | "Release Mid-term Showcase results?" | ops assessments | "families notified" · danger · irreversible |
| mWd | "Withdraw Ka-yan from JC InnoTech?" | guardian request | refund-window band shown before confirm |
| mRevoke | "Withdraw consent — JC InnoTech, Ka-yan" | guardian consent | D1 revocation; participation consequence stated |
| mFps | "Bank transfer / FPS" | guardian pay | the D-6 reference-submission leg |
| mTeamCode | "Join with an invite code" | student team | single-use code entry (B-2/M-1) |
| mTeamCreate | "Create a team" | student pool | name + intro + visibility (B-1 fields) |
| mProgDetails | "{programme} — full details" | marketplace | read-only long-form |
| (2 further utility wraps) | filters/details | staff | — |
Pattern: every modal = title-as-question · consequence sentence · quiet Cancel + one committed action (gold, or danger for release/withdraw). `data-nosheet` marks the two that never collapse to a bottom-sheet on phone — all others render as sheets in the family frame.

### The notification drawer (`#notifPanel`, 380px, L1950; model `NOTIFS` L2493)
Per-persona item sets: student ("Workshop 3 is on Saturday" · "Consent still waiting on your guardian" · "Design gate passed") · guardian ("Sign consent for Ka-yan" · "Payment due for Ka-ho" · "Receipt issued") · mentor ("You run Workshop 3 on Saturday" · "1 score still open") · school ("2 consents expire within 7 days") · finance ("Invoice aging past 30 days"). Item affordances: open-target navigation + **Snooze** ("Snoozed 3 hours — deadline reminders return 24 hours before expiry" / "until tomorrow 09:00") + **Mute type** with the guard *"deadline and safety notices can never be muted"*. Empty state: "All caught up." → This is the D6/B-19 domain's UI contract, drawn: typed notifications · per-type mute with a safety-critical never-mute class · snooze with re-arm.

### Recurring in-page action vocabulary (frequency-ranked from every `onclick`/label)
Row navigation to records (`go('ops-stu')` ×5, `go('sch-student')` ×4) · Approve/Decline pairs (queues) · **Remind / Remind guardian** ×6 (toast "Reminder sent…" — D6) · attendance **Present / No-show / Partial · 50%** three-state per student (toasts "Attendance recorded: …") · composer **Edit team / Save changes / Cancel** (compSave/compCancel) · **Booked** state chips ×4 · consent chase **Sign / Sent / Viewed** state column · finance **Record / Confirm / Record refund** · `Request withdrawal…` ×2 (opens mWd) · `Request to join` (toast: "the Team Lead and academy will review" — the B-4 semantics) · Materials · 1 links · back-links `‹ {parent}` on every drill-in (stu-space, gua-sign, ops-comp, men-sessdet…).

### Interaction rules the file encodes (beyond §8)
- **Back-link discipline:** every drill-in screen opens with `‹ ParentName`; no breadcrumbs anywhere.
- **stopPropagation on row actions** inside clickable cards (gua-home: `event.stopPropagation();go('gua-sign')`) — the prototype acknowledges the nested-interactive problem our a11y divergence ruled against.
- **Toasts confirm every non-navigating action**, always stating the consequence, never instructions.
- **Filters are subtabs** (programme pickers), not dropdowns; only staff tables get them.
- **Attendance is tri-state** (present / no-show / partial-50%) — richer than the build's toggle ⚠️ and than the binary the design's C-8 request grammar assumed; alignment item.

## 9 · PROTOTYPE ELEMENTS ALREADY OVERRULED BY THE MODEL (D-7, carried from Doc 1)
5-seg segbar (display fold ruled) · "· 5 members"/"Led by {name}" on the lobby wall · tracker now/locked + "Unlocks when…" + locked-gate requirement titles · "My guardians" on stu-me · "Edit roster" on confirmed team · mentor flag-off card · notify promises ("You'll be notified…") · review-semantics copy on direct-insert join. ⛔ harness: persona bar, Simulate release, demo-control cards.

---

*Next: Document 3 — `KAP-ALIGNMENT-PLAN.md`: Doc 1 (system as-built/as-designed) vs this document, with the prototype UIUX as the base; per-screen match plan, each gap classed (client / read-widen / migration / domain / D-ruling) and sequenced.*

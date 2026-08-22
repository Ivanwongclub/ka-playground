# KAP — PROTOTYPE WORKFLOW & UIUX
### What `docs/design/KAP-Prototype.html` actually specifies, screen by screen
**Document 2 of 3** · 20 Aug 2026 · derived solely from the HTML (387,150 B / 57 screens / 51 `data-p` blocks); line numbers cite the file. Harness elements (not product) are marked ⛔.

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

**stu-home L422** — greeting eyebrow ("GOOD AFTERNOON / Ka-yan") on photo hero · **NEXT UP** card: session title · programme+venue · date/time + `Booked` chip · **My programmes**: per-enrolment banner cards, 5-seg segbar (Submitted·Pool·Teamed·Confirmed·Active), one status line + ONE action (Confirmed→chip · in-pool→"Waiting for your guardian" + `Remind`). [Verified against L422-447 on 21 Aug (DOC-FIX-2): FAITHFUL, including the photo hero, which lives in the `.home-hero` CLASS (L327-331: `--img-a` + two scrims + a phone-frame bleed) rather than the markup — read the class, not the body.]
**stu-progs L448** — h2 "My programmes", then the enrolment-card list (banner · **a muted TERM + status chip line between band and segbar** — L452 `Sep – Dec 2026` + `Confirmed`, L459 `Oct 2026 – Jan 2027` + `In pool`; that term string is the **MG/RW item Doc 3 tracks**, and this is the screen it belongs on · segbar · state-dependent rows: confirmed = Team/Next session/Tracker/Results; in-pool = Consent(`Waiting for your guardian` chip + a quiet **`Remind`** button, L464)/Team("Not yet · 3 teams forming"+gold `Find a team`)/Next session). Rows are value-present, not state-switched.
**stu-space L470** — THE scoped space: back-link `‹ Programmes` · a **122px band header** (L472: eyebrow "**Programme**" + h2 title + status chip + a **`⌄` PROGRAMME SWITCHER** opening `#progMenu`, `setProg()`) + 5 sub-tabs. NB the band carries no term — the term line lives on stu-progs (L452):
- **Journey**: 3 stat tiles (Team / Next session / Consent, ghost cards) → gold stepper with dated knots → "What happens next" tile.
- **Team** `t-team`: teamed = roster (initials av + name + role, "(you)"), Mentor plain-name, "Join requests · you are Team Lead" (Accept gold/Decline); in-pool = "No team yet · In the pool" + `Join with code` + gold `Create a team` + lobby wall ("Led by {name} · Public" + `Request to join`).
- **Sessions**: rows title · date+venue · Booked/`Book` (gold), Materials link.
- **Tracker** `t-track` L559: header + "Learn · 1 of 4 met" chip · 5 gate pills (done ✓ / now / locked) · gate-detail: chip + "Passed 12 Jul by the academy" line + requirement rows (title + evidence line + Met/Pending chip + `Submit` on team deliverables). GATES model at L2412.
- **Results** `t-res` L564: per assessment — pre-release: pend chip "In progress — results not yet released" + notify line; released: ok chip + **34px gold score** "/100" + "Released {date}". ⛔ "Demo control / Simulate release" card.
**stu-explore L583 → stu-progdet L620** — marketplace cards (banner, blurb, fee, window) → detail: hero, About, schedule preview, fee, gold `Ask my guardian to enrol me` (interest ping, not an enrolment).
**stu-me L661** — profile identity, school, language, **"My guardians"** list, sign-out. (CORRECTED 23 Aug, AUDIT-3 B-1 — the previous note called the guardians list "an ungranted read as-built" and classed it D-7. That was wrong: `guardian_links_read` always admitted the student (`student_id::text = app.actor_id`); what was missing was a ROUTE. **`GET /my/guardians` now serves it** — S-READ-3 item 1, `api/app/Http/Controllers/MyLinksController.php::guardians`, names resolved for ACTIVE links only via the AD-2 display-name elevation (rulings F-1/F-2), nameless while a link is pending. Built on stu-me by B9. Reclassed **PW → RW → BUILT**; struck from §9.)

**Student workflows encoded:** browse→interest ping→(guardian enrols)→consent-wait (`Remind`)→pool→find/create/join team→submit→confirmed→book sessions→attend→tracker requirements→released results. Every gap between these steps is a chip + one action on the card where it matters.

---

## 2 · GUARDIAN (9 screens, phone)

**gua-home L701** — NO hero: a bare `.eyebrow` "Guardian" + `<h2>` name (L702-703). Then ONE card,
`border-left:4px solid var(--warn)`, headed **"Action required · {n}"** (L704-705), holding one `.todo-row`
per NEED (L706-714): `.glyph` icon · `<h4>` title carrying the subject — "Sign consent for {child}",
"Pay {amount} for {child}" · `.who` sub-line (programme · detail) · `.todo-deadline` (bold `var(--warn)`
line + uppercase 11px `small`) · a `›` chevron. The WHOLE ROW is the affordance (`onclick`, `cursor:pointer`,
`:hover h4 {color:gold}`) — there are **no buttons, no chips, no countdown pills** anywhere in the block.
Per-type emphasis, per the file's own screen note at L2174 ("Action-required list: bold titles, bold
deadlines — expires_at and payment_due_at as the loudest thing on the page"): the consent row bolds
"6 days left" over a small "expires 19 Aug"; the payment row bolds "Due 20 Aug" over a small "7 days left".
Then `<h3 class="sect">Children</h3>` (L715) + one card per child: `.av` initials · name · "{n} enrolments"
· `›` (L716-722). **No stat trio.**
> CORRECTED 21 Aug (B2-GUA-HOME). The previous row described the BUILT dashboard — hero, gold
> `Review & sign` / `View payments` CTAs, a blue countdown chip and a Children/Outstanding/Enrolments stat
> trio — none of which exists at L701-729. Cause: the row was written with the build's screenshots in recent
> memory rather than from the block. Verified against the markup, the class CSS (`.todo-row` L349-357) and
> the screen note (L2174).
**gua-children L730** — h2 "Children"; child cards: `.av` 42px + name + muted "{school} · {n} enrolments". Rows are PROGRAMME-SCOPED in their label and several carry a GOLD action: **"Consent · Summer Venture"** → `Expires in 6 days` chip + gold **`Sign`** (L734 → gua-sign) · **"Fees · JC InnoTech"** → `HK$1,800 · due 20 Aug` chip + gold **`Pay`** (L740 → gua-pay) · "{programme}" → "Confirmed · Team Aurora" / "Teamed · awaiting confirmation" · "Next session" → "Workshop 3 · Sat 16 Aug 10:00". Trailing **"Enrol a child"** ghost card with gold `Enrol in a programme…`.
> PROTOTYPE DEFECT (not ours to match): card 1 carries `cursor:pointer` + `onclick="go('gua-child')"` (L732) and card 2 carries **neither** (L738) — the whole-card drill is inconsistent between two identical cards.
**gua-child L750** — back-link `‹ Children` · identity row: `.av` 46px + h2 name + a **school CHIP** (L755) · `<h3 class="sect">Enrolments</h3>` · then one CARD per enrolment (L757-774), each: `.prog-band` + `.segbar` + four chip rows — **Consent** (`Signed 5 Jul` / `Expires in 6 days` + gold **`Sign`**) · **Fees** (`Settled · R-2026-00981` / `HK$1,800 · order issued`) · **Team** (`Team Aurora` / `Awaiting team formation`) · **Next session** (+ a quiet **`Materials`** button) — and, right-aligned at the card foot, a quiet **`Request withdrawal…`** (L772 → the mWd modal). Cards drill → gua-space.
**gua-space L777** — back-link `‹ Ka-yan` · a **112px `.prog-band` header** (L779: eyebrow "Ka-yan · Programme" + h2 programme + status chip) · three subtabs (Journey · Fees · the combined mirror). **Journey** holds TWO cards: "Where Ka-yan is" (stepper + a muted summary line) and a **"Withdrawal" card** (L793-796) whose consequence copy reads "If you withdraw Ka-yan, the refund window is shown before you confirm anything." above a quiet `Request withdrawal…`. **Fees** is an order TABLE (Line/Amount/**State**, each line carrying its own `Paid` chip) with "Receipt R-2026-00981 · 8 Jul 2026" as muted TEXT — not a link. The guardian mirror: L812 states Team/Sessions/Tracker/Results "composed identically to Ka-yan's own view… the guardian additions are **Fees and signing authority**."
**gua-consents L818** — h2 "Consents"; `.inbox-item` rows titled "{programme} — for {child}" with a `.who` sub-line carrying STATE + TEMPLATE VERSION + LANGUAGE ("Viewed · template v3.2" · "Signed 5 Jul · v3.2 · English" · "Signed 6 Jul · v3.2 · Chinese (Trad.)"). The list is not a pending-queue: it shows SIGNED history too, and a signed row carries a quiet **`Withdraw…`** (L828 → the mRevoke modal — D1 consent revocation).
> ℹ️ AS-BUILT DIVERGENCE, DELIBERATE (AUDIT-3 §A.4, recorded so it is never re-found as a miss). `Consents.tsx` renders TWO HEADED REGISTERS — "Awaiting your signature · {n}" and "History" — where this block has ONE flat list. The ROW treatments do map faithfully (pencil glyph + warn chip + clickable vs ✓ glyph + ok chip + inert), and the build cites exactly that at `web/src/pages/Consents.tsx:77-84`. But the **headings, the count badge and the amber rail card are build-added structure this block does not have**, and "History" is a build-authored string that appears nowhere in the prototype. Ruled deliberate (mobile scanning); not a prototype fact. Only the outstanding row is clickable → **gua-sign L839, the ceremony:** "‹ Consents" back · "6 days" chip · "For {child} · rendered in English · v3.2" · numbered consent text (participation / health / media / data / withdrawal) · "— End of consent text —" · **"Signing is locked — read to the end first"** scroll gate · affirmation checkbox · **"Media consent — optional"** separate `Allow` toggle ("Declining does not affect participation") · signature pad ("Tap to sign") · gold `Sign consent` · "recorded immutably with the exact version you read."
**gua-pay L866** — h2 "Payments". PAYABLE card (L869-878): "Ka-ho · JC InnoTech" + `Due 20 Aug` chip · a LINE TABLE (Line/Amount: Programme fee HK$1,500 · Materials HK$300) · a total row "**Total HK$1,800**" beside "Reference **KAP-01044**" (the reference is the card's gold VALUE, not an action) · two buttons: gold **`Pay online`** (L875) and quiet **`Bank transfer / FPS`** (L876 → the mFps modal). SETTLED card (L880-882): "Ka-yan · JC InnoTech" + `Settled` chip + muted "HK$1,800 · Receipt R-2026-00981 · 8 Jul 2026". Then a **Refunds ghost card** (L883-886): "No withdrawal requests. If you request one, the refund window in force is shown before you confirm."
> ⚠️ D-6 stands, now attached to the REAL affordance: the guardian-side remittance leg is `Bank transfer / FPS` → mFps, while as-designed B-17 grants the remittance declaration to the SCHOOL (sch-bill `Mark remitted`). Unruled divergence.
> CORRECTED 21 Aug (DOC-FIX-2). The previous row said `Get payment link` and "I've paid — submit reference" — neither string exists in the prototype (`grep "Get payment link"` → 0 hits); "Get payment link" is a **build** label from `web/src/i18n/locales/en.json`. Same described-the-build contamination as the gua-home row (8142231), and it contradicted §8A, which labels mFps correctly.
**gua-reqs L890** — **Requests**: withdrawal/change requests list, state chips (pending endorsement / with academy / decided), `New request`. (As-built: API only.)
**gua-me L905** — identity, language, notification prefs, **"Link another child"** (pairing ceremony entry), sign-out.

> **CROSS-CUTTING — the withdrawal affordance has THREE entry points**, and no single row shows that: `gua-child` L772 (quiet `Request withdrawal…` at the foot of each enrolment card) · `gua-space` Journey L793-796 (a dedicated "Withdrawal" card with consequence copy) · and the **mWd modal** itself (§8A), which states the refund-window band before the guardian confirms. A DU card for the guardian withdrawal path must scope all three together — building one leaves the other two dead, and the refund-window promise lives in the modal, not in either entry.

**Guardian workflows encoded:** everything actionable surfaces on Home the moment it exists; sign ceremony = scroll→affirm→(optional media)→signature→immutable record; pay = link out; requests = submit + track states; linking = ceremony from Me.

---

## 3 · MENTOR (6 screens, desktop)

**men-teams L942** — team cards (programme, stage chip, members, next session) → **men-team L960**: roster+roles, stage tracker, consent-status column (status only), check-in notes area, gate `Approve` where authorised.
**men-sess L992 → men-sessdet L1004** — sessions list → detail (L1004-1020): back-link `‹ My Sessions` · record-head (eyebrow "Session · JC InnoTech", h2 "Workshop 3 · Design Sprint", `Sat 16 Aug 10:00` chip) · attendee table **Attendee | Booking | Attendance** where each row carries exactly TWO controls — **`Present`** and **`No-show`** (L1013; toasts "Attendance recorded: attended" / "…: no-show") · then a "Session materials" card.
> CORRECTED 21 Aug (DOC-FIX-2). The previous row said "toggles (present/absent/late)" — three states, two of them invented. Attendance in the prototype is **BINARY**, which is also what the model stores (`session_bookings.status ∈ attended|no_show`) and what C-8's request grammar assumed. No divergence exists here; the earlier row manufactured one.
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
**sch-chase L1267** — Consents ③: record-head (eyebrow "Consent chase · sorted by expiry", h2 "**4 consents outstanding**") over a **highlight strip** — Expiring ≤ 7 days `2` (warn) · Expired `1` (danger) · Signed this week `9` (ok). Then a per-**STUDENT** table (Student | Programme | State | Expires | action), the student name a `link-name` → sch-student. TWO different actions, not one: **`Remind guardian`** on an outstanding row, and **`Request re-issue`** on an EXPIRED row (L1283) — a distinct capability, not a louder reminder. The school never signs.
**sch-bill L1289** — Billing ①: consolidated invoices, line = student×programme, aging state, `Mark remitted` declaration (B-17 — the school-side counterpart of D-6).

---

## 5 · OPS (18 screens, desktop) — the deepest persona

**ops-appr L1353** — Approvals ③: eyebrow "Queue", h2 "Approvals · 3", then ONE table (Request | Programme | Origin | Submitted | actions) — not three queues. The Request cell names the subject ("Guardian link — Mr. Ho → Ho Tsz-ching", "Registration — new guardian (Mrs. Lau)"); ORIGIN is a column, carrying `Pairing code` · `Self-registration` · `School-mediated` (the vouch path). Actions are **`Approve` / `Decline`** per row.
> CORRECTED 21 Aug (DOC-FIX-2). The previous row said "vouch queues" (origin is a column of one table), "oldest-first" (the rows run 11 Aug, 11 Aug, 10 Aug — NEWEST first) and "approve/reject with consequence copy" (the label is Decline, and no consequence copy appears in the block).
**ops-board L1366** — **Team Formation**: occupancy header per programme (In pool 14 · Forming 3 · Submitted 1 · Confirmed 6 · lobby + freshness line) · programme table (window, counts, Attention) → **ops-comp L1439**: "Team Nova — composition": `Edit team`/`Cancel`/`Save changes` · left = pool list (name, form, consent chip, "+11 more filtered") · right = roster n-of-max with roles — drag-to-compose, then confirm ceremony (consequence copy: seats+money fire).
**ops-consops L1471** — Consent ops ②: templates/versions, re-consent fan-out, void.
**ops-wd L1483** — Withdrawals: eyebrow "Queue", h2 "Withdrawals · **2**", table Student | Programme | **Refund window** | Requested | action. The refund-window band IS the row's evidence, as a chip: `Full refund window` · `Partial · 50%` ✓.
> ⚠️ PROTOTYPE/SPEC CONFLICT (flagged, not corrected — the doc reports the block): the ops row's action button reads **`Endorse`**, but under OD-26 endorsement is the SCHOOL's pastoral act and the ACADEMY decides. Either the prototype mislabels the ops control or it duplicates the school's. Wants a ruling before the ops-wd card runs.
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
**fin-conf L1800** — To confirm: eyebrow "Payments · awaiting second-officer confirmation", h2 "To confirm · 3", table Ref | **Person** | Programme · School | Amount | **Recorded by** | action. Two things the block makes explicit and this row previously carried neither:
> · **The Person column is blanked to "—"**, with the stated reason "finance capability alone is not entitled to student names" (L1813). That is the prototype taking Proposal E2's side of the **D-3** dispute (AD-2 shows the child's display name to finance). The ruling should know the prototype has a position.
> · A **"Viewing as" officer switcher** (Officer Yuen / Officer Ma) sits in the head — a demo control ⛔ — with the note "Switch the viewing officer — the confirm control flips between enabled and disabled-with-reason. Four-eyes: recording and confirming are different people."
> The `Recorded by` column carries officer NAMES (Officer Yuen / Ma / Cheng); the row's `Confirm` button is present only where the viewing officer is not the recorder.
> CORRECTED 21 Aug (DOC-FIX-2). The previous row said own-recorded rows show **"You"** — that is the BUILD's marker (`Payments.tsx`, the "You" pill), not the prototype's. Same described-the-build contamination as gua-home (8142231) and gua-pay (above).
**fin-orders L1826** — orders & receipts explorer (line items, receipt chain).
**fin-refunds L1839** — Refunds ①: approve → second-officer confirm; credit notes.
**fin-aging L1851** — consolidated invoice aging (school-settled), exception rows.
**fin-recon L1871** — reconciliation: nightly result + manual reconcile action, assertion list.

---

## 7 · AUDIT (2 screens, desktop)

**aud-log L1903** — Audit explorer: eyebrow "Append-only ledger", h2, a filter strip (**Filter · entity** = "Enrolment ENR-2026-04471" · **Events** `14`) over a table **When | Actor | Action | Programme | Detail | Reason**. Actors are ROLE-PREFIXED ("system", "fin: Officer Ma", "guardian: Mrs. Chan"); Detail carries the evidence inline ("v3.2 · English · sha256 9f2c…8ae1", "PAY-08644 · HK$1,800"); Reason is its own column. Zero write affordance ✓.
> CORRECTED 21 Aug (DOC-FIX-2). The previous row claimed "before/after images" — the block has no before/after columns (they are a MODEL feature of `audit_events`, and the built AdminAudit renders them). Doc-level contamination from the model/build rather than the block; what the block actually shows is Detail + Reason.
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
Row navigation to records (`go('ops-stu')` ×5, `go('sch-student')` ×4) · Approve/Decline pairs (queues) · **Remind / Remind guardian** ×6 (toast "Reminder sent…" — D6) · attendance **Present / No-show** per student — BINARY (toasts "Attendance recorded: attended / no-show") · composer **Edit team / Save changes / Cancel** (compSave/compCancel) · **Booked** state chips ×4 · consent chase **Sign / Sent / Viewed** state column · finance **Record / Confirm / Record refund** · `Request withdrawal…` ×2 (opens mWd) · `Request to join` (toast: "the Team Lead and academy will review" — the B-4 semantics) · Materials · 1 links · back-links `‹ {parent}` on every drill-in (stu-space, gua-sign, ops-comp, men-sessdet…).

### Interaction rules the file encodes (beyond §8)
- **Back-link discipline:** every drill-in screen opens with `‹ ParentName`; no breadcrumbs anywhere.
- **stopPropagation on row actions** inside clickable cards (gua-home: `event.stopPropagation();go('gua-sign')`) — the prototype acknowledges the nested-interactive problem our a11y divergence ruled against.
- **Toasts confirm every non-navigating action**, always stating the consequence, never instructions.
- **Filters are subtabs** (programme pickers), not dropdowns; only staff tables get them.
- **Attendance is BINARY** (Present / No-show, L1013) — which the build's toggle and C-8's request grammar both already match. No alignment item.
  > CORRECTED 21 Aug (DOC-FIX-2). This bullet and the vocabulary line above previously claimed a three-state "Partial · 50%" attendance control and raised an alignment item on it. Cause: the vocabulary was gathered by grepping labels across the whole file, which swept in **refund-window** strings — "Partial · 50%" is a refund BAND (L1845 refund row · L2025 the mWd modal's "Refund window in force"), never an attendance state.

## 9 · PROTOTYPE ELEMENTS ALREADY OVERRULED BY THE MODEL (D-7, carried from Doc 1)
5-seg segbar (display fold ruled) · "· 5 members"/"Led by {name}" on the lobby wall · tracker now/locked + "Unlocks when…" + locked-gate requirement titles · "Edit roster" on confirmed team · mentor flag-off card · notify promises ("You'll be notified…") · review-semantics copy on direct-insert join. ⛔ harness: persona bar, Simulate release, demo-control cards.

---

*Next: Document 3 — `KAP-ALIGNMENT-PLAN.md`: Doc 1 (system as-built/as-designed) vs this document, with the prototype UIUX as the base; per-screen match plan, each gap classed (client / read-widen / migration / domain / D-ruling) and sequenced.*

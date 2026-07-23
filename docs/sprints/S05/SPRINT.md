# SPRINT KAP-S05 — Teams, roles & Activity Tracker

## GOAL
Team formation with all 12 gap mechanisms closed, rotational leadership as a ledger, and stage gates
that can prove why they opened.

## PRECONDITIONS  S04B gate PASSED.

## IMPLEMENTS  Spec team sections · 2.21 extension

## SCOPE
1. Team formation full close-loop (all 12 gap mechanisms from the spec) · exception queue
   (orphans / sub-minimum / leaderless).
2. Role assignment with cardinality · rotation engine + tenure ledger.
3. Stage gates (fixed stages) with live condition evaluation · condition snapshot stored at
   decision time.
4. Activity Tracker UI as a view over modules · activation sequencing.
5. **Extend Withdrawal Cascade (2.21)**: withdrawal ends team membership + open tenures, routes
   sub-minimum teams to the exception queue.

## NON-SCOPE
Sessions/attendance (S06) · badges minting (S08 — tenures recorded now, minted later) · team finance (S07).

## KEY VERIFICATIONS
- Second active team for the same student+programme → DB-level rejection, paste.
- Gate passes → snapshot stored; underlying condition then broken in fixture → assertion catches it.
- Withdraw a teamed student → membership ended, tenure closed, team flagged if sub-minimum.

## AUDIT ELEMENT
**Team Governance Report** — formation approvals with reasons · exception queue history · role
tenure ledger · gate decisions with condition snapshots.

## ASSERTIONS (--tag=S05)
One active team per student per programme (DB-enforced, probed) · every Passed gate's conditions
still hold · Withdrawal Cascade extended (team/tenure legs live) · badge==tenure parity ships
(fires fully in S08).

## EXIT GATE  Tests + tag + cascade assertion green. AUDIT.md, gate commit.

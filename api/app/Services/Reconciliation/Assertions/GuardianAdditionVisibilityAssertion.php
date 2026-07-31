<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04D STEP 3 (OD-24 — never silent). When a guardian is ADDED to a student who
 * already had a guardian, every EARLIER guardian must have a visibility record
 * for that addition — vouched additions included (OD-30). "An admin can approve a
 * link no current guardian knows about" is exactly the silent failure this guards.
 *
 * Predicate (immutable facts): for every active guardian_link L, every OTHER
 * active guardian of the same student whose link was created BEFORE L must have a
 * link_visibility_events record addressed to them for L. A missing one is silent.
 */
class GuardianAdditionVisibilityAssertion implements Assertion
{
    public function key(): string
    {
        return 'links.guardian_addition_visibility';
    }

    public function proves(): string
    {
        return 'every guardian ADDED to a student who already had a guardian left a visibility record for each earlier guardian — no addition is silent (OD-24, vouched included)';
    }

    public function cites(): string
    {
        return 'OD-24 · OD-30 · S04D STEP 3';
    }

    public function tags(): array
    {
        return ['S04D'];
    }

    public function check(): AssertionResult
    {
        $silent = DB::select(
            "SELECT l.id AS link_id, earlier.guardian_id AS unnotified
             FROM guardian_links l
             JOIN guardian_links earlier
               ON earlier.student_id = l.student_id
              AND earlier.status = 'active'
              AND earlier.guardian_id <> l.guardian_id
              AND earlier.created_at < l.created_at
             WHERE l.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM link_visibility_events v
                   WHERE v.new_link_id = l.id AND v.addressed_guardian_id = earlier.guardian_id
               )"
        );

        if ($silent !== []) {
            $ex = implode(', ', array_map(fn ($r) => "link {$r->link_id}→guardian {$r->unnotified}", array_slice($silent, 0, 5)));

            return AssertionResult::fail(count($silent)." guardian addition(s) left an earlier guardian unnotified (OD-24 never silent): {$ex}");
        }

        return AssertionResult::pass('every guardian addition notified each earlier guardian — never silent');
    }
}

<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04D STEP 1 (2.30) — the all-three-tables provenance backstop. Every ACTIVE
 * link — guardian, school, teacher — must carry a `to_state='active'` audit
 * event: an approving decision (approveLink / D-i approve / schoolVouch /
 * invitation / bulk) OR the audited `legacy-approved` backfill marker. No active
 * link may exist without a recorded activation — there is no third path to
 * `active`.
 *
 * Distinct from `links.activation_audited` (guardian-only, `--tag=S06`, the
 * consent dependency): this one is the S04D provenance guarantee across ALL link
 * types. The tags reflect WHY each exists (consent vs provenance), not just what
 * they scan. This one caught the pre-S04D un-audited invitation `teacher_links`.
 */
class NoActiveWithoutApprovalAssertion implements Assertion
{
    private const TABLES = [
        'guardian_link' => 'guardian_links',
        'school_link' => 'school_links',
        'teacher_link' => 'teacher_links',
    ];

    public function key(): string
    {
        return 'links.no_active_without_approval';
    }

    public function proves(): string
    {
        return 'every active link (guardian, school, teacher) carries a to_state=\'active\' audit — an approving decision or the audited legacy-approved backfill; no active link exists without a recorded activation';
    }

    public function cites(): string
    {
        return '2.30 · OD-23 · OD-27 · S04D STEP 1';
    }

    public function tags(): array
    {
        return ['S04D'];
    }

    public function check(): AssertionResult
    {
        $orphans = [];
        foreach (self::TABLES as $entity => $table) {
            $rows = DB::select(
                "SELECT l.id FROM {$table} l
                 WHERE l.status = 'active'
                   AND NOT EXISTS (
                       SELECT 1 FROM audit_events ae
                       WHERE ae.entity_type = '{$entity}' AND ae.entity_id = l.id::text AND ae.to_state = 'active'
                   )"
            );
            foreach ($rows as $r) {
                $orphans[] = "{$entity}:{$r->id}";
            }
        }

        if ($orphans !== []) {
            return AssertionResult::fail(
                count($orphans)." active link(s) have NO to_state='active' audit — an active link with no recorded activation (".implode(', ', array_slice($orphans, 0, 5)).')'
            );
        }

        return AssertionResult::pass('every active guardian/school/teacher link carries a to_state=\'active\' activation audit');
    }
}

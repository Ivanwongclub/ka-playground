<?php

namespace App\Http\Controllers;

use App\Services\Authz\ScopeContext;
use App\Services\Consent\ConsentSigningService;
use App\Services\Teams\TeamConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S-UX3-3a — the ops-facing per-member consent status for the 成團 gate. Response is BOOLEANS/COUNTS
 * ONLY (§1 allowlist): team_id, mode, all_satisfied, blocking_count, members[{student_id, student_name,
 * satisfied, signed_count, guardian_count, blocker}]. NO guardian id/name/request row/timestamp ever
 * leaves it. ADVISORY — the confirm-time FOR SHARE re-check in TeamConfirmationService is the authority.
 *
 * The endpoint OBSERVES the `not_requested` blocker but never issues the missing request — resolving it
 * is the separate audited path (ReissueConsentOnGuardianActivation / enrolment-creation issuance); this
 * read is never an issuance trigger and offers no "fix from here" control.
 */
class TeamConsentStatusController extends Controller
{
    public function __construct(
        private readonly TeamConfirmationService $confirmation,
        private readonly ConsentSigningService $consent,
        private readonly ScopeContext $scope,
    ) {}

    public function show(Request $request, string $team): JsonResponse
    {
        // Fetched under the CALLER'S RLS: a team the caller cannot see is simply absent — 404, never a
        // 403 existence leak (unaffiliated school-admin / non-member → 404).
        $t = DB::table('teams')->where('id', $team)->first() ?? abort(404);

        // OD-39 authority (the SAME gate as 成團 confirm): a caller who can SEE the team but is not a
        // lobby school-admin / academy ops·super (e.g. a guardian, a student member) → 403.
        $this->confirmation->assertApprover($t, $request->user());

        // Elevated read: members' consent lies outside the caller's derived scope; the elevation
        // returns aggregate booleans/counts + the member's own name only — no guardian identity.
        return response()->json($this->scope->asSystem(
            'Team consent roster (S-UX3-3a): aggregate per-member consent booleans/counts for the 成團 gate. The members and their guardians are outside the caller\'s derived scope; only booleans, counts and the member student\'s own name leave the elevation — no guardian id, name, request row, timestamp or signing order (child-safety privacy allowlist). Advisory read; the confirm-time FOR SHARE re-check is the authority.',
            function () use ($t): array {
                $members = DB::table('team_members as tm')
                    ->leftJoin('users as u', 'u.id', '=', 'tm.student_id')
                    ->where('tm.team_id', $t->id)->where('tm.status', 'active')
                    ->orderBy('u.name')
                    ->get(['tm.student_id', 'u.name as student_name']);

                $requiresAll = false;
                $rows = $members->map(function ($m) use ($t, &$requiresAll): array {
                    $s = $this->consent->consentSummary((int) $t->programme_id, (int) $m->student_id);
                    $requiresAll = $s['requires_all'];

                    return [
                        'student_id' => (int) $m->student_id,
                        'student_name' => $m->student_name,
                        'satisfied' => $s['satisfied'],
                        'signed_count' => $s['signed_count'],
                        'guardian_count' => $s['guardian_count'],
                        'blocker' => $s['blocker'],
                    ];
                });

                return [
                    'team_id' => $t->id,
                    'mode' => $requiresAll ? 'requires_all' : 'any-one',
                    'all_satisfied' => $rows->every(fn ($r) => $r['satisfied']),
                    'blocking_count' => $rows->reject(fn ($r) => $r['satisfied'])->count(),
                    'members' => $rows->values()->all(),
                ];
            },
        ));
    }
}

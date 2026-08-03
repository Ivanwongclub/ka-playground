<?php

namespace App\Http\Controllers;

use App\Services\Authz\ScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S-UX3-3b STEP 1 — Backend delta B2: the member-readable team roster (GET /teams/{team}/members).
 *
 * AUTHORITY (five-branch, established BEFORE the elevation):
 *   The team is fetched under the CALLER'S RLS (absent → 404). A caller who can see a member row of the
 *   team — an active MEMBER (own team_members row), a GUARDIAN of a member (student_id ∈ their children),
 *   or academy OPS/audit/super — is entitled to NAMES. A caller who sees the team but no member row
 *   (a joinable `lobbyWall` forming team) gets a COUNT ONLY, never names. A caller who cannot see the team
 *   at all → 404.
 *
 * ELEVATION — a NEW allowlisted asSystem, child-safety-adjacent. `tm_read` walls a student off from
 * co-members (a student reads only their OWN team_members row), so returning any teammate data — or an
 * accurate member count — requires crossing that wall. The elevation exists for the NARROW purpose of
 * teammate DISPLAY NAMES + team ROLE + the true count. WITHHELD, never returned: consent status (that is
 * the ops-only STEP-1 roster, OD-39), enrolment status, guardian identity, payment/obligation — anything
 * family / consent / money. For a non-member (joinable team) only the count leaves the elevation.
 */
class TeamMembersController extends Controller
{
    public function __construct(private readonly ScopeContext $scope) {}

    private const REASON = 'Team roster read (S-UX3-3b B2): a member (or their guardian, or ops) reads their own team\'s roster. tm_read walls a student off from co-members, so this elevation crosses that wall for the NARROW purpose of returning teammate DISPLAY NAMES + team ROLE + the accurate member count — nothing else. Consent status, enrolment status, guardian identity and money/obligations are WITHHELD (consent is the ops-only STEP-1 roster). For a caller who is not a member (a joinable lobby team) only the count is returned, never names. Authority (active membership / guardian / ops) was established before the elevation.';

    public function index(Request $request, string $team): JsonResponse
    {
        // RLS-shaped: a team the caller cannot see is absent → 404 (member / guardian / ops / joinable-
        // lobby wall all pass teams_read; everyone else 404s — no existence leak).
        $t = DB::table('teams')->where('id', $team)->first() ?? abort(404);

        // Entitled to NAMES iff the caller can see a member row of this team under their OWN RLS — true for
        // a member (own row), a guardian of a member (child's row), or ops (all rows); false for a caller
        // who sees the team only via the joinable-lobby wall.
        $seesMembers = DB::table('team_members')
            ->where('team_id', $t->id)->where('status', 'active')->exists();

        return response()->json($this->scope->asSystem(self::REASON, function () use ($t, $seesMembers): array {
            // system scope: the TRUE count (tm_read would undercount for a student).
            $count = DB::table('team_members')->where('team_id', $t->id)->where('status', 'active')->count();

            if (! $seesMembers) {
                // joinable lobby team, caller is not a member → COUNT ONLY, never teammate names.
                return ['team_id' => $t->id, 'member_count' => $count, 'members' => null];
            }

            // full roster: names + team role (active tenure per member) + count. NO consent / guardian /
            // enrolment / money — the child-safety allowlist.
            $members = DB::table('team_members as tm')
                ->leftJoin('users as u', 'u.id', '=', 'tm.student_id')
                ->leftJoin('tenures as tn', function ($j) use ($t) {
                    $j->on('tn.team_id', '=', 'tm.team_id')->on('tn.student_id', '=', 'tm.student_id')
                        ->where('tn.state', '=', 'active')->where('tn.team_id', '=', $t->id);
                })
                ->leftJoin('role_library as rl', 'rl.id', '=', 'tn.role_id')
                ->where('tm.team_id', $t->id)->where('tm.status', 'active')
                ->orderBy('u.name')
                ->get(['tm.student_id', 'u.name as student_name', 'rl.name_en as role_en', 'rl.name_tc as role_tc', 'rl.name_sc as role_sc']);

            return [
                'team_id' => $t->id,
                'member_count' => $count,
                'members' => $members->map(fn ($m): array => [
                    'student_id' => (int) $m->student_id,
                    'student_name' => $m->student_name,
                    'role' => $m->role_en !== null
                        ? ['name_en' => $m->role_en, 'name_tc' => $m->role_tc, 'name_sc' => $m->role_sc]
                        : null,
                ])->all(),
            ];
        }));
    }
}

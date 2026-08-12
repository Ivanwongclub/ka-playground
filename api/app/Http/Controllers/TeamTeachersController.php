<?php

namespace App\Http\Controllers;

use App\Services\Authz\ScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S-MENTOR-1 (ruling 4) — GET /teams/{team}/teachers. ONE read serving all three visibility surfaces (ops
 * drawer, student/guardian team card, the teacher's own view): the team's LINKED-TEACHER DISPLAY NAMES.
 *
 * AUTHORITY (same wall as the roster read): the team is fetched under the CALLER'S RLS (absent → 404). A
 * caller who can see a member row of the team — an active member, a guardian of a member, academy ops, or a
 * config-gated linked teacher — is entitled to the teacher names. A caller who sees the team but no member
 * row (a joinable lobby) gets an empty list, never names.
 *
 * ELEVATION: team_teacher_links_read admits only the linked teacher themselves / ops / school-admin — NOT a
 * student or guardian. So resolving the teacher NAMES for a member/guardian crosses that wall for the NARROW
 * purpose of returning, per active link, {teacher_id, display name}. WITHHELD: everything else — no email, no
 * contact, no student/consent/money data.
 */
class TeamTeachersController extends Controller
{
    public function __construct(private readonly ScopeContext $scope) {}

    private const REASON = 'Team-teachers read (S-MENTOR-1 ruling 4): a caller who can see the team\'s roster — an active member, their guardian, academy ops, or the config-gated linked teacher — reads the team\'s LINKED-TEACHER display names. team_teacher_links_read admits only the linked teacher / ops / school-admin, so resolving the teacher NAMES for a member or guardian crosses that wall for the NARROW purpose of returning, per active link, the teacher_id and display name. WITHHELD: everything else — no email, no contact, no student, consent or money data. Authority (the caller sees a member row of this team under their own RLS) was established before the elevation.';

    public function index(Request $request, string $team): JsonResponse
    {
        // RLS-shaped: a team the caller cannot see is absent → 404 (member / guardian / ops / linked-teacher
        // all pass teams_read where entitled; everyone else 404s — no existence leak).
        $t = DB::table('teams')->where('id', $team)->first() ?? abort(404);

        // Entitled to teacher NAMES iff the caller can see a member row of this team under their OWN RLS.
        $seesMembers = DB::table('team_members')
            ->where('team_id', $t->id)->where('status', 'active')->exists();
        if (! $seesMembers) {
            return response()->json(['team_id' => $t->id, 'teachers' => []]);
        }

        return response()->json($this->scope->asSystem(self::REASON, function () use ($t): array {
            $teachers = DB::table('team_teacher_links as ttl')
                ->leftJoin('users as u', 'u.id', '=', 'ttl.teacher_id')
                ->where('ttl.team_id', $t->id)->where('ttl.status', 'active')
                ->orderBy('u.name')
                ->get(['ttl.teacher_id', 'u.name as teacher_name']);

            return [
                'team_id' => $t->id,
                'teachers' => $teachers->map(fn ($x): array => [
                    'teacher_id' => (int) $x->teacher_id,
                    'teacher_name' => $x->teacher_name,
                ])->all(),
            ];
        }));
    }
}

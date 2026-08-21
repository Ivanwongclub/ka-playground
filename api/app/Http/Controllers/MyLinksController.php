<?php

namespace App\Http\Controllers;

use App\Services\Authz\ScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S-READ-3 item 1 — the two family LINK reads, one per persona:
 *   GET /my/children   (role:guardian) — the guardian's own guardian_links
 *   GET /my/guardians  (role:student)  — the student's own guardian_links
 *
 * Both are SELF reads of rows the caller's own RLS already admits. `guardian_links_read` carries an arm for
 * each side and both are STATUS-BLIND:
 *     OR guardian_id::text = current_setting('app.actor_id')   -- the guardian's arm
 *     OR student_id::text  = current_setting('app.actor_id')   -- the student's arm
 * so the link ROW needs no elevation and no policy change. What was missing was never permission — it was an
 * endpoint. These close the register→link→enrol dead-end (the Marketplace child picker derives children from
 * ENROLMENT rows, so a newly-linked child with no enrolment is invisible and cannot be enrolled), B5's "My
 * guardians" card, gua-me's linked-children count, and the zero-enrolment invisibility flag.
 *
 * NAMES ARE STATUS-GATED — ACTIVE LINKS ONLY, both sides (ruling F-1/F-2):
 *   · The two-stage ceremony (OD-23/OD-27) exists so the academy vets a relationship BEFORE the guardian is
 *     treated as connected. Showing a child's NAME to a not-yet-approved guardian would hand the ceremony's
 *     prize out early. A pending row renders as status alone, nameless — that is the model working, not a gap.
 *   · Mirrored on the student side for the same reason.
 *
 * WHY ONLY ONE SIDE ELEVATES:
 *   · guardian → child: FREE. `users_read` admits `actor_role='guardian' AND id = ANY(app.student_ids)`, and
 *     ScopeContext derives student_ids from ACTIVE links only. So the caller's own RLS already returns exactly
 *     the active children's rows and nothing else. No elevation exists on this path to get wrong: if the arm
 *     ever narrows, a name goes NULL — it cannot leak.
 *   · student → guardian: `users_read` has NO arm admitting a student to any other user row. So the guardian's
 *     display name needs the AD-2 elevation (ruling F-2), in AD-2's exact shape — display-name-only, resolved
 *     AFTER the caller-RLS fetch, for ids ALREADY in that payload, never a row the caller did not reach.
 *
 * NOT SERVED, deliberately: no email, no phone, no address, no date of birth, no school. student_id/guardian_id,
 * display name and link status — nothing else. Asserted against the raw response body, not just its keys.
 */
class MyLinksController extends Controller
{
    /** Byte-matches config/scope-elevations.php — asSystem throws if it drifts (ScopeContext:139-143). */
    public const REASON = 'Student-side guardian names (S-READ-3 F-2): resolve the DISPLAY NAME only of the caller\'s own ACTIVE guardians, for the guardian_ids already present in the caller-RLS guardian_links rows. users_read has no arm admitting a student to another user row, so the AD-2 attribution pattern carries the name; non-active links stay nameless (F-1 mirrored) and no row the caller did not already reach is read.';

    public function __construct(private readonly ScopeContext $scope) {}

    /** GET /my/children — the guardian's own links. A guardian with none gets [], never a 404. */
    public function children(Request $request): JsonResponse
    {
        // Caller RLS decides visibility; the explicit where is defense in depth, not the gate. (A guardian is
        // never also a student — roles are never stacked, Spec B1 — so the sibling arm cannot widen this.)
        $links = DB::table('guardian_links')
            ->where('guardian_id', $request->user()->id)
            ->orderBy('created_at')
            ->get(['student_id', 'status']);

        // ACTIVE only, and read under the CALLER's own RLS — no elevation on this path (see the class note).
        $activeIds = $links->where('status', 'active')->pluck('student_id')->all();
        $names = $activeIds === [] ? [] : DB::table('users')->whereIn('id', $activeIds)->pluck('name', 'id')->all();

        return response()->json(['data' => $links->map(fn ($l): array => [
            'student_id' => (int) $l->student_id,
            'name' => $names[$l->student_id] ?? null, // null for every non-active link, by ruling
            'status' => $l->status,
        ])->all()]);
    }

    /** GET /my/guardians — the student's own links. Same shape from the other side. */
    public function guardians(Request $request): JsonResponse
    {
        $links = DB::table('guardian_links')
            ->where('student_id', $request->user()->id)
            ->orderBy('created_at')
            ->get(['guardian_id', 'status']);

        $activeIds = $links->where('status', 'active')->pluck('guardian_id')->all();
        // Inlined HERE, not in a helper: asSystem derives its call site from the backtrace, so the registered
        // caller must be MyLinksController::guardians (the same reason OrdersController::index inlines it).
        $names = $activeIds === [] ? [] : $this->scope->asSystem(self::REASON, fn (): array => DB::table('users')
            ->whereIn('id', $activeIds)->pluck('name', 'id')->all());

        return response()->json(['data' => $links->map(fn ($l): array => [
            'guardian_id' => (int) $l->guardian_id,
            'name' => $names[$l->guardian_id] ?? null,
            'status' => $l->status,
        ])->all()]);
    }
}

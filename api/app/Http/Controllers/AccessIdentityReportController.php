<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * S01 audit element: Access & Identity Report (behind audit_read) —
 * invitation funnel, auth event log, active links per student,
 * sole-guardian/replacement exceptions, capability grants log.
 */
class AccessIdentityReportController extends Controller
{
    private const AUTH_ACTIONS = [
        'login', 'logout', 'failed_login', 'lockout', 'lockout_cleared',
        'reset_requested', 'reset_completed', 'invitation_accepted', 'email_verified',
    ];

    public function index(): JsonResponse
    {
        $issued = DB::table('invitations')->count();
        $accepted = DB::table('invitations')->whereNotNull('accepted_at')->count();
        $verified = DB::table('invitations')
            ->join('users', 'users.id', '=', 'invitations.user_id')
            ->whereNotNull('users.email_verified_at')->count();

        return response()->json([
            'funnel' => ['issued' => $issued, 'accepted' => $accepted, 'verified' => $verified],
            'auth_events' => DB::table('audit_events')
                ->whereIn('action', self::AUTH_ACTIONS)
                ->orderByDesc('occurred_at')->limit(50)
                ->get(['occurred_at', 'action', 'actor_id', 'actor_role', 'entity_id', 'reason']),
            'links_per_student' => DB::table('guardian_links')
                ->join('users', 'users.id', '=', 'guardian_links.student_id')
                ->where('guardian_links.status', 'active')
                ->groupBy('users.id', 'users.name')
                ->orderBy('users.name')
                ->get([DB::raw('users.id AS student_id'), 'users.name', DB::raw('count(*) AS active_links')]),
            'sole_guardian_students' => DB::table('guardian_links AS gl')
                ->join('users', 'users.id', '=', 'gl.student_id')
                ->where('gl.status', 'active')
                ->groupBy('users.id', 'users.name')
                ->havingRaw('count(*) = 1')
                ->get([DB::raw('users.id AS student_id'), 'users.name']),
            'replacement_exceptions' => DB::table('guardian_replacement_exceptions')
                ->where('status', 'open')->orderBy('deadline')
                ->get(['id', 'student_id', 'reason', 'deadline', 'created_at']),
            'capability_log' => DB::table('audit_events')
                ->where('action', 'like', 'capability.%')
                ->orderByDesc('occurred_at')->limit(50)
                ->get(['occurred_at', 'action', 'actor_id', 'entity_id', 'reason', 'payload_after']),
            // S04C STEP 4 — the onboarding front door: funnel, queue age, held ledger
            'onboarding' => [
                'funnel' => [
                    'submitted' => DB::table('registration_requests')->count(),
                    'approved' => DB::table('registration_requests')->where('status', 'approved')->count(),
                    'declined' => DB::table('registration_requests')->where('status', 'declined')->count(),
                    'verified' => DB::table('registration_requests AS rr')
                        ->join('users AS u', 'u.id', '=', 'rr.created_account_id')
                        ->where('rr.status', 'approved')->whereNotNull('u.email_verified_at')->count(),
                ],
                'queue' => [
                    'pending_accounts' => DB::table('registration_requests')->where('status', 'submitted')->count(),
                    'pending_links' => DB::table('guardian_links')->where('status', 'pending_approval')->count(),
                    'held' => DB::table('held_links')->where('status', 'held')->count(),
                    'open_escalations' => DB::table('onboarding_exceptions')->where('status', 'open')->count(),
                    'oldest_pending_days' => (int) (DB::table('registration_requests')->where('status', 'submitted')->min('created_at')
                        ? now()->diffInDays(DB::table('registration_requests')->where('status', 'submitted')->min('created_at'), absolute: true) : 0),
                ],
                'held_link_ledger' => [
                    'held' => DB::table('held_links')->where('status', 'held')->count(),
                    'materialised' => DB::table('held_links')->where('status', 'materialised')->count(),
                    'expired' => DB::table('held_links')->where('status', 'expired')->count(),
                ],
                // S04D STEP 4 — bulk student creation by school (roll authority visible to the academy)
                'bulk_created_by_school' => DB::table('school_links')
                    ->where('status', 'active')->where('origin', 'bulk')
                    ->join('schools', 'schools.id', '=', 'school_links.school_id')
                    ->groupBy('schools.id', 'schools.name_en')
                    ->orderBy('schools.name_en')
                    ->get([DB::raw('schools.id AS school_id'), 'schools.name_en', DB::raw('count(*) AS created')]),
            ],
        ]);
    }
}

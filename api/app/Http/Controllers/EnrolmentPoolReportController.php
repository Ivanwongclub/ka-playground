<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * S04A audit element: Enrolment & Pool Report — pool depth per programme
 * against the formation deadline, timelines with acting guardian, consent-
 * issuance health (surfaces lost jobs), withdrawal pipeline. audit_read.
 */
class EnrolmentPoolReportController extends Controller
{
    public function index(): JsonResponse
    {
        $pool = DB::table('enrolments as e')
            ->join('programmes as p', 'p.id', '=', 'e.programme_id')
            ->groupBy('e.programme_id', 'p.code')
            ->orderBy('p.code')
            ->get([
                'e.programme_id', 'p.code',
                DB::raw("count(*) FILTER (WHERE e.status = 'pending_consent') as pending_consent"),
                DB::raw("count(*) FILTER (WHERE e.status = 'in_pool') as in_pool"),
                DB::raw("count(*) FILTER (WHERE e.status IN ('teamed','confirmed','active')) as teamed_plus"),
                DB::raw("count(*) FILTER (WHERE e.status IN ('withdrawn','released','completed')) as terminal"),
            ])->map(function ($row) {
                $row->formation_deadline_on = json_decode((string) DB::table('wizard_sections')
                    ->where('programme_id', $row->programme_id)->where('section_key', 'team_rules')
                    ->value('data'), true)['formation_deadline_on'] ?? null;

                return $row;
            });

        $timelines = DB::table('enrolments as e')
            ->join('users as g', 'g.id', '=', 'e.acting_guardian_id')
            ->join('users as s', 's.id', '=', 'e.student_id')
            ->orderByDesc('e.created_at')->limit(100)
            ->get(['e.id', 'e.programme_id', 'e.status', 'e.created_at', 's.name as student_name', 'g.name as acting_guardian']);

        $issuanceGaps = DB::select("
            SELECT e.id FROM enrolments e
            JOIN guardian_links gl ON gl.student_id = e.student_id AND gl.status = 'active'
            WHERE e.status IN ('pending_consent','in_pool')
              AND NOT EXISTS (SELECT 1 FROM consent_requests cr
                WHERE cr.programme_id = e.programme_id AND cr.student_id = e.student_id
                  AND cr.signer_id = gl.guardian_id AND cr.status IN ('sent','viewed','signed'))
            GROUP BY e.id");

        $withdrawals = DB::table('withdrawal_requests as wr')
            ->leftJoin('users as d', 'd.id', '=', 'wr.decided_by')
            ->orderByDesc('wr.created_at')->limit(100)
            ->get(['wr.id', 'wr.enrolment_id', 'wr.status', 'wr.reason', 'wr.created_at', 'wr.decided_at', 'd.name as decided_by_name']);

        return response()->json([
            'pool_by_programme' => $pool,
            'timelines' => $timelines,
            'issuance_gaps' => array_column($issuanceGaps, 'id'),
            'withdrawal_pipeline' => $withdrawals,
        ]);
    }
}

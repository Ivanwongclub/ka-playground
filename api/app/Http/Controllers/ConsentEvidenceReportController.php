<?php

namespace App\Http\Controllers;

use App\Services\Consent\EvidenceBundleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * S03 audit element: Consent Evidence Report — coverage by template version AND
 * language, outstanding/declined/superseded/voided lists, and the per-signature
 * evidence bundle export. Behind audit.read; reads are additionally RLS-shaped.
 */
class ConsentEvidenceReportController extends Controller
{
    public function index(): JsonResponse
    {
        $coverage = DB::table('consent_signatures as s')
            ->join('consent_template_versions as v', 'v.id', '=', 's.template_version_id')
            ->join('consent_requests as r', 'r.id', '=', 's.request_id')
            ->groupBy('v.template_id', 'v.language', 'v.version', 'v.is_placeholder')
            ->orderBy('v.language')->orderBy('v.version')
            ->get([
                'v.template_id', 'v.language', 'v.version', 'v.is_placeholder',
                DB::raw('count(*) as signatures'),
                DB::raw("count(*) FILTER (WHERE r.status = 'signed') as active"),
                DB::raw("count(*) FILTER (WHERE r.status IN ('superseded','voided')) as superseded_or_voided"),
            ]);

        // S-UX2b: additive display names via LEFT JOINs (never drop a row); RLS-gated per joined table.
        $byStatus = fn (array $statuses) => DB::table('consent_requests as r')
            ->leftJoin('programmes as p', 'p.id', '=', 'r.programme_id')
            ->leftJoin('users as s', 's.id', '=', 'r.student_id')
            ->leftJoin('users as sg', 'sg.id', '=', 'r.signer_id')
            ->whereIn('r.status', $statuses)->orderBy('r.created_at')
            ->get([
                'r.id', 'r.template_id', 'r.programme_id', 'r.student_id', 'r.signer_id', 'r.status', 'r.created_at',
                'p.name_en as programme_name_en', 'p.name_tc as programme_name_tc', 'p.name_sc as programme_name_sc',
                's.name as student_name', 'sg.name as signer_name',
            ]);

        return response()->json([
            'coverage_by_version_and_language' => $coverage,
            'outstanding' => $byStatus(['sent', 'viewed']),
            // S-TTL-1 RIDER — the fifth bucket, landing in the SAME commit as the sweeper that creates the
            // state. Without it `consents:expire` would move a request out of `outstanding` and into no
            // bucket at all: the sweeper would silently remove work from the only ops consent surface. A
            // lapsed consent is not resolved, it is unresolved and out of time, so it must stay visible.
            'expired' => $byStatus(['expired']),
            'declined' => $byStatus(['declined']),
            'superseded' => $byStatus(['superseded']),
            'voided' => $byStatus(['voided']),
            'placeholder_signatures' => DB::table('consent_signatures as s')
                ->join('consent_template_versions as v', 'v.id', '=', 's.template_version_id')
                ->where('v.is_placeholder', true)->count(), // R15 exposure at a glance
        ]);
    }

    /** The bundle a legal challenge would demand — one signature, one ZIP. */
    public function bundle(string $signatureId, EvidenceBundleService $bundles): BinaryFileResponse
    {
        DB::table('consent_signatures')->where('id', $signatureId)->first() ?? abort(404);
        $path = $bundles->build($signatureId);

        return response()->download($path, "consent-evidence-{$signatureId}.zip")->deleteFileAfterSend();
    }
}

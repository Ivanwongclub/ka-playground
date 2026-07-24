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

        $byStatus = fn (array $statuses) => DB::table('consent_requests')
            ->whereIn('status', $statuses)->orderBy('created_at')
            ->get(['id', 'template_id', 'programme_id', 'student_id', 'signer_id', 'status', 'created_at']);

        return response()->json([
            'coverage_by_version_and_language' => $coverage,
            'outstanding' => $byStatus(['sent', 'viewed']),
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

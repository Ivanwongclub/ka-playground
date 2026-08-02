<?php

namespace App\Http\Controllers;

use App\Services\Consent\ConsentSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentRequestController extends Controller
{
    public function __construct(private readonly ConsentSigningService $signing) {}

    /** Void a request whose frozen merge data no longer matches source (ops). */
    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5'],
            'reissue' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->signing->voidRequest(
            $this->findOr404($id), $data['reason'], $request->user(), (bool) ($data['reissue'] ?? false),
        ));
    }

    /** Derived consent status for the actor's student — booleans only (ruling 2). */
    public function derivedStatus(Request $request, int $studentId): JsonResponse
    {
        $programmeId = (int) $request->validate(['programme_id' => ['required', 'integer']])['programme_id'];

        return response()->json($this->signing->derivedStatus($programmeId, $studentId, $request->user()));
    }

    /**
     * RLS-shaped list: each session sees exactly its branch of the read set.
     * S-UX2b: additive display names via LEFT JOINs (never drop a row); each name is gated by the
     * joined table's own RLS (programmes, users_read) — resolves iff the caller could read it, else NULL.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('consent_requests as r')
            ->leftJoin('programmes as p', 'p.id', '=', 'r.programme_id')
            ->leftJoin('users as s', 's.id', '=', 'r.student_id')
            ->leftJoin('users as sg', 'sg.id', '=', 'r.signer_id')
            ->orderBy('r.created_at')
            ->get([
                'r.id', 'r.template_id', 'r.programme_id', 'r.student_id', 'r.signer_id', 'r.status', 'r.expires_at',
                'p.name_en as programme_name_en', 'p.name_tc as programme_name_tc', 'p.name_sc as programme_name_sc',
                's.name as student_name', 'sg.name as signer_name',
            ])]);
    }

    /** Render the document to the ADDRESSED SIGNER; the served language is recorded server-side. */
    public function document(Request $request, string $id): JsonResponse
    {
        return response()->json($this->signing->renderForSigner(
            $this->findOr404($id), $request->query('language', 'en'), $request->user(),
        ));
    }

    /** The signer reports reaching the end — recorded as a server-side event. */
    public function scrolled(Request $request, string $id): JsonResponse
    {
        $this->signing->recordScrolledToEnd($this->findOr404($id), $request->user());

        return response()->json(['recorded' => true]);
    }

    /** The signature itself. Route-guarded by permission:consent.sign (guardian-only, S01). */
    public function sign(Request $request, string $id): JsonResponse
    {
        $signatureId = $this->signing->sign(
            $this->findOr404($id),
            $request->only(['affirmed', 'method', 'strokes', 'typed_name', 'image_base64']),
            $request->user(), (string) $request->ip(), (string) $request->userAgent(),
        );
        $signature = DB::table('consent_signatures')->where('id', $signatureId)->first();

        return response()->json([
            'signature_id' => $signatureId,
            'language' => $signature->language,
            'template_sha256' => $signature->template_sha256,
            'rendered_sha256' => $signature->rendered_sha256,
            'signed_at' => $signature->signed_at,
        ], 201);
    }

    /** Signature evidence — RLS-shaped: signer alone among portal roles + compliance staff. */
    public function signatures(): JsonResponse
    {
        return response()->json(['data' => DB::table('consent_signatures as cs')
            ->leftJoin('users as sg', 'sg.id', '=', 'cs.signer_id') // S-UX2b: additive signer_name, RLS-gated, never drops a row
            ->orderBy('cs.signed_at')
            ->get(['cs.id', 'cs.request_id', 'cs.signer_id', 'cs.language', 'cs.template_sha256', 'cs.rendered_sha256', 'cs.method', 'cs.signed_at', 'sg.name as signer_name'])]);
    }

    /** FR037 decline: terminal, reasoned, audited. Signer only. */
    public function decline(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5']])['reason'];
        $this->signing->decline($this->findOr404($id), $reason, $request->user());

        return response()->json(['status' => 'declined']);
    }

    /** Evidence documents — RLS-shaped: signer's own + compliance staff. */
    public function documents(): JsonResponse
    {
        return response()->json(['data' => DB::table('consent_documents as cd')
            ->leftJoin('users as sg', 'sg.id', '=', 'cd.signer_id') // S-UX2b: additive signer_name, RLS-gated, never drops a row
            ->orderBy('cd.created_at')
            ->get(['cd.id', 'cd.signature_id', 'cd.request_id', 'cd.signer_id', 'cd.language', 'cd.pdf_sha256', 'cd.generator', 'cd.created_at', 'sg.name as signer_name'])]);
    }

    /** The signed PDF itself (FR038). BI-10: 409 until the scan passes. */
    public function download(string $id)
    {
        $document = DB::table('consent_documents')->where('id', $id)->first() ?? abort(404);
        $bytes = app(\App\Services\Consent\ConsentDocumentService::class)->download($document);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"consent-{$document->request_id}.pdf\"",
        ]);
    }

    private function findOr404(string $id): object
    {
        // RLS shapes this read: a request not in the session's read set is
        // simply absent — 404, never 403's existence leak
        return DB::table('consent_requests')->where('id', $id)->first() ?? abort(404);
    }
}

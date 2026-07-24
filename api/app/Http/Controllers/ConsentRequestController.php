<?php

namespace App\Http\Controllers;

use App\Services\Consent\ConsentSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentRequestController extends Controller
{
    public function __construct(private readonly ConsentSigningService $signing) {}

    /**
     * Ops issuance (operations.manage) — S04A will issue per enrolment and
     * narrow the write set back to system-only. Reason is REQUIRED and audited
     * with the operator (Leo ruling 1): temporary widening, named for reversal.
     */
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'uuid'],
            'programme_id' => ['required', 'integer'],
            'student_id' => ['required', 'integer'],
            'signer_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:5'],
        ]);
        $id = $this->signing->issueRequest(
            $data['template_id'], $data['programme_id'], $data['student_id'], $data['signer_id'],
            $request->user(), $data['reason'],
        );

        return response()->json(['id' => $id], 201);
    }

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

    /** RLS-shaped list: each session sees exactly its branch of the read set. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('consent_requests')
            ->orderBy('created_at')
            ->get(['id', 'template_id', 'programme_id', 'student_id', 'signer_id', 'status', 'expires_at'])]);
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
        return response()->json(['data' => DB::table('consent_signatures')
            ->orderBy('signed_at')
            ->get(['id', 'request_id', 'signer_id', 'language', 'template_sha256', 'rendered_sha256', 'method', 'signed_at'])]);
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
        return response()->json(['data' => DB::table('consent_documents')
            ->orderBy('created_at')
            ->get(['id', 'signature_id', 'request_id', 'signer_id', 'language', 'pdf_sha256', 'generator', 'created_at'])]);
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

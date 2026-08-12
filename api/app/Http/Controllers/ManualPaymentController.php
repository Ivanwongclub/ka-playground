<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Services\Authz\ScopeContext;
use App\Services\Money\ManualPaymentService;
use App\Services\Uploads\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManualPaymentController extends Controller
{
    // S-UX-AUDIT-1 (AD-2): the SAME governed reason as OrdersController — display-name-only attribution.
    private const ATTR_REASON = 'Order attribution (S-UX-AUDIT-1 AD-2): resolve student DISPLAY NAME only for the student_ids already present in the caller-RLS order rows, so a finance-only actor (users_read nulls student rows for them) sees whose order they settle. Display name ONLY — no email, no DOB, no id beyond the payload\'s student_id. Resolved AFTER the caller-RLS fetch, one narrow call per read.';

    public function __construct(private readonly ManualPaymentService $payments, private readonly ScopeContext $scope) {}

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'note' => ['sometimes', 'string'],
            'evidence' => ['required', 'array', 'min:1'],
            'evidence.*' => ['file'],
        ]);
        $payment = $this->payments->record(
            $data['order_id'], (int) $data['amount_minor'], $data['currency'],
            $request->file('evidence', []), $data['note'] ?? null, $request->user(),
        );

        return response()->json(['id' => $payment->id, 'status' => $payment->status], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $this->payments->confirm($id, $request->user());

        return response()->json(['status' => 'confirmed']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5']])['reason'];
        $this->payments->reject($id, $reason, $request->user());

        return response()->json(['status' => 'rejected']);
    }

    /** RLS-shaped within the finance.view gate (Member 403 preserved from S01). */
    public function index(): JsonResponse
    {
        // S-UX3-2: additive names. recorded_by/confirmed_by resolve for finance staff (academy_admins
        // are mutually visible under users_read) — so the confirmer can SEE the recorder (BI-9 legibility).
        // The STUDENT name is NOT joined here (users_read nulls student rows for finance-only actors); it is
        // resolved below via the governed display-name-only elevation (S-UX-AUDIT-1 AD-2).
        $rows = DB::table('payments as p')
            ->leftJoin('orders as o', 'o.id', '=', 'p.order_id')
            ->leftJoin('programmes as pr', 'pr.id', '=', 'o.programme_id')
            ->leftJoin('users as rb', 'rb.id', '=', 'p.recorded_by')
            ->leftJoin('users as cb', 'cb.id', '=', 'p.confirmed_by')
            ->orderBy('p.created_at')
            ->get(['p.id', 'p.order_id', 'o.student_id', 'p.origin', 'p.provider', 'p.amount_minor', 'p.currency', 'p.via_link', 'p.status', 'p.recorded_by', 'p.confirmed_by', 'p.paid_at',
                'p.note', // R1-F1 (item 3a): the recorder's note beside the confirm control — own column, no wall
                'rb.name as recorded_by_name', 'cb.name as confirmed_by_name',
                'pr.name_en as programme_name_en', 'pr.name_tc as programme_name_tc', 'pr.name_sc as programme_name_sc']);

        $ids = $rows->pluck('student_id')->filter()->unique()->all();
        $names = $ids === [] ? [] : $this->scope->asSystem(self::ATTR_REASON, fn (): array => DB::table('users')
            ->whereIn('id', $ids)->pluck('name', 'id')->all());
        $rows->each(fn ($p) => $p->student_name = $names[$p->student_id] ?? null);

        return response()->json(['data' => $rows]);
    }

    /**
     * R1-F1 (item 3b) — evidence METADATA beside the confirm control (the four-eyes control's missing half).
     * RLS-shaped: pe_read admits a caller who can see the parent payment; `uploads` is global. Finance-gated
     * at the route. Scan status is returned HONESTLY (the client renders pending/quarantined; only clean
     * files are downloadable). No file bytes here — this is the list; the download is a separate endpoint.
     */
    public function evidence(Request $request, string $id): JsonResponse
    {
        $rows = DB::table('payment_evidence as pe')
            ->join('uploads as u', 'u.id', '=', 'pe.upload_id')
            ->where('pe.payment_id', $id)
            ->orderBy('pe.created_at')
            ->get(['pe.upload_id', 'u.original_name', 'u.mime_type', 'u.size_bytes', 'u.status', 'u.scanned_at']);

        return response()->json(['data' => $rows]);
    }

    /**
     * R1-F1 (item 3b) — stream ONE evidence file. The upload is resolved ONLY via the payment_evidence →
     * uploads join keyed on {payment id + upload_id} — NO client-supplied path ever touches storage (no
     * traversal surface). Only status='clean' streams (via UploadService::contents, BI-10); pending or
     * quarantined → 409 with a body the client renders as "scanning…" / "failed scan", never a silent 404.
     * Content-Disposition carries a SANITISED original_name.
     */
    public function evidenceDownload(Request $request, string $id, string $uploadId): \Symfony\Component\HttpFoundation\Response
    {
        // The join is the ONLY resolver — an upload not linked to THIS payment (or a payment the caller
        // cannot see under pe_read) is absent → 404. No path, no id, is trusted from the client beyond these.
        $link = DB::table('payment_evidence')->where('payment_id', $id)->where('upload_id', $uploadId)->first();
        if ($link === null) {
            abort(404);
        }
        $upload = Upload::query()->findOrFail($uploadId);
        if ($upload->status !== Upload::STATUS_CLEAN) {
            // 409, never a silent 404 — the client renders the honest scan state.
            return response()->json(['status' => $upload->status, 'message' => 'Evidence not downloadable (scan status: '.$upload->status.')'], 409);
        }
        $bytes = app(UploadService::class)->contents($upload); // refuses anything not clean (BI-10)
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($upload->original_name ?: 'evidence'));

        return response($bytes, 200, [
            'Content-Type' => $upload->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
        ]);
    }
}

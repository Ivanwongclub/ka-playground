<?php

namespace App\Http\Controllers;

use App\Services\Authz\ScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * S-UX-AUDIT-1 (AD-2) — the orders read, moved out of an inline route closure so the student-attribution
 * elevation resolves to a REGISTERED Class::method call site (asSystem derives the site from the backtrace;
 * a closure has none). The order rows are fetched under the CALLER'S RLS first; only then are the DISPLAY
 * NAMES of the student_ids ALREADY in that payload resolved — see REASON. Nothing else crosses users_read.
 */
class OrdersController extends Controller
{
    public function __construct(private readonly ScopeContext $scope) {}

    private const REASON = 'Order attribution (S-UX-AUDIT-1 AD-2): resolve student DISPLAY NAME only for the student_ids already present in the caller-RLS order rows, so a finance-only actor (users_read nulls student rows for them) sees whose order they settle. Display name ONLY — no email, no DOB, no id beyond the payload\'s student_id. Resolved AFTER the caller-RLS fetch, one narrow call per read.';

    public function index(): JsonResponse
    {
        // Caller-RLS fetch: which ORDERS are visible is decided by orders_read; the programme name resolves
        // for everyone. student_name is NOT joined here — finance-only actors get NULL from users_read; it is
        // filled below via the governed elevation, display-name only.
        $rows = DB::table('orders as o')
            ->leftJoin('programmes as pr', 'pr.id', '=', 'o.programme_id')
            ->orderBy('o.created_at')
            ->get(['o.id', 'o.enrolment_id', 'o.programme_id', 'o.student_id', 'o.payer_party', 'o.status', 'o.total_amount_minor', 'o.currency', 'o.payment_due_at',
                'pr.name_en as programme_name_en', 'pr.name_tc as programme_name_tc', 'pr.name_sc as programme_name_sc']);

        // The elevation is inlined HERE (not a helper) so asSystem's backtrace caller is OrdersController::index
        // — the exact key registered in config/scope-elevations.php. student_ids are ALREADY in the payload.
        $ids = $rows->pluck('student_id')->filter()->unique()->all();
        $names = $ids === [] ? [] : $this->scope->asSystem(self::REASON, fn (): array => DB::table('users')
            ->whereIn('id', $ids)->pluck('name', 'id')->all());
        $rows->each(fn ($o) => $o->student_name = $names[$o->student_id] ?? null);

        return response()->json(['data' => $rows]);
    }
}

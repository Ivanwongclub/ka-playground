<?php

namespace App\Http\Controllers;

use App\Services\Money\PaymentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentLinkController extends Controller
{
    public function __construct(private readonly PaymentLinkService $links) {}

    /** The plaintext token leaves the platform exactly once, in this response. */
    public function mint(Request $request, string $id): JsonResponse
    {
        return response()->json($this->links->mint($id, $request->user()), 201);
    }

    /** RLS-shaped; token_hash is deliberately NOT selected (ruling 6). */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('payment_links')->orderBy('created_at')
            ->get(['id', 'order_id', 'minted_by', 'status', 'amount_minor', 'currency', 'expires_at', 'paid_at'])]);
    }

    /** Anonymous, multi-view. One shape for every non-payable state: 404. */
    public function resolve(string $token): JsonResponse
    {
        $payload = $this->links->resolve($token);

        return $payload === null
            ? response()->json(['message' => 'Not found'], 404)
            : response()->json($payload);
    }

    /** Anonymous, single ACT. The race loser gets the same 404 as everyone. */
    public function confirm(string $token): JsonResponse
    {
        $result = $this->links->confirmPayment($token);

        return $result === null
            ? response()->json(['message' => 'Not found'], 404)
            : response()->json($result);
    }
}

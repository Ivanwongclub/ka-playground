<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Authz\CapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapabilityController extends Controller
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    public function grant(Request $request): JsonResponse
    {
        return $this->act($request, 'grant');
    }

    public function revoke(Request $request): JsonResponse
    {
        return $this->act($request, 'revoke');
    }

    private function act(Request $request, string $verb): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'capability' => ['required', 'string', 'in:'.implode(',', CapabilityService::CAPABILITIES)],
        ]);

        $grantee = User::query()->findOrFail($validated['user_id']);
        // Authorisation lives in the service so a refusal is audited before the 403
        $this->capabilities->{$verb}($request->user(), $grantee, $validated['capability']);

        return response()->json(['status' => 'ok']);
    }
}

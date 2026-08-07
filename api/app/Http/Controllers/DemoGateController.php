<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The PUBLIC DEMO front-door — NOT real authentication. A shared access code, layered
 * strictly ON TOP of the app so the public demo URL is not wide-open to crawlers or
 * casual visitors. It never touches Sanctum, sessions, or RLS — those remain the real
 * security controls beneath it (you still log in with a seeded account to see data).
 * Disabled entirely unless DEMO_MODE is on, so local/CI/real-prod are unaffected.
 *
 * The code lives ONLY server-side — config('demo.access_code') ← the DEMO_ACCESS_CODE
 * Cloud Run secret — never shipped in the SPA bundle, and rotatable without a rebuild.
 * On success we set a cookie whose value is an HMAC of a constant KEYED BY the code, so
 * a client cannot forge it and rotating the code invalidates every outstanding cookie.
 * (This route has no EncryptCookies middleware, so the value is read back verbatim.)
 */
class DemoGateController extends Controller
{
    private const COOKIE = 'kap_demo_gate';

    /** GET /api/demo/gate — is demo mode on, and is this visitor already through the gate? */
    public function status(Request $request): JsonResponse
    {
        $enabled = (bool) config('demo.enabled');

        return response()->json([
            'demo' => $enabled,
            'open' => ! $enabled || $this->passes($request),
        ]);
    }

    /** POST /api/demo/gate — exchange the shared code for an unforgeable gate cookie. */
    public function enter(Request $request): JsonResponse
    {
        $code = (string) config('demo.access_code');

        // Demo off or no code configured ⇒ the gate is open; nothing to check.
        if (! config('demo.enabled') || $code === '') {
            return response()->json(['open' => true]);
        }
        if (! hash_equals($code, (string) $request->input('code', ''))) {
            return response()->json(['open' => false, 'error' => 'invalid'], 422);
        }

        // 30-day cookie; HttpOnly; Secure when the request is HTTPS; SameSite=Lax.
        $cookie = cookie(self::COOKIE, $this->expected($code), 60 * 24 * 30, '/', null, $request->isSecure(), true, false, 'Lax');

        return response()->json(['open' => true])->withCookie($cookie);
    }

    private function passes(Request $request): bool
    {
        $code = (string) config('demo.access_code');
        if ($code === '') {
            return true; // demo on but no code set ⇒ no gate
        }

        return hash_equals($this->expected($code), (string) $request->cookie(self::COOKIE, ''));
    }

    /** Unforgeable cookie value: HMAC of a constant, keyed by the (secret) access code. */
    private function expected(string $code): string
    {
        return hash_hmac('sha256', 'kap-demo-gate/v1', $code);
    }
}

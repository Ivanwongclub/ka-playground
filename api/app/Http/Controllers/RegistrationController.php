<?php

namespace App\Http\Controllers;

use App\Services\Identity\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The anonymous self-registration surface (S04C STEP 1, OD-23). Both endpoints
 * are guest-accessible and throttled. submit() returns ONE constant shape (202 +
 * opaque reference) for every outcome — accepted, honeypot-dropped, or a
 * registrant/counterpart that already exists — so nothing here is an enumeration
 * oracle. There is deliberately NO status endpoint.
 */
class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    /** Opt-in listed partner schools for the picker + a fresh form nonce (fill-time). */
    public function schools(): JsonResponse
    {
        return response()->json([
            'schools' => $this->registration->listedSchools(),
            'form_nonce' => $this->registration->mintNonce(),
        ]);
    }

    /** Constant-shape 202. Validation is on SHAPE only (format/length) — never existence. */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:student,guardian'],
            'applicant_name' => ['required', 'string', 'max:120'],
            'applicant_email' => ['required', 'email', 'max:190'],
            'applicant_phone' => ['nullable', 'string', 'max:40'],
            'preferred_language' => ['required', 'in:en,zh-TC,zh-SC'],
            'date_of_birth' => ['nullable', 'date'],
            // routing is confined to LISTED schools or the academy — an unlisted id
            // is rejected, preserving the direct-to-academy path (no unlisted gap).
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->where('public_listing', true)],
            'counterpart_email' => ['nullable', 'email', 'max:190'],
            'counterpart_name' => ['nullable', 'string', 'max:120'],
            // bot-confinement fields (never persisted): honeypot + form nonce
            'website' => ['nullable', 'string', 'max:190'],
            'form_nonce' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(
            ['status' => 'received'] + $this->registration->submit($validated),
            202
        );
    }
}

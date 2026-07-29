<?php

namespace App\Services\Identity;

use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-registration (S04C STEP 1, OD-23) — the platform's first anonymous write.
 *
 * The two disciplines that must hold, and where they live:
 *  1. CONSTANT SHAPE. submit() NEVER queries for the existence of the applicant
 *     OR the counterpart. It inserts (or silently drops on a bot signal) and
 *     returns one opaque reference, identically, for all four enumeration cases
 *     (registrant new/exists, counterpart new/exists). There is no status
 *     endpoint. The counterpart is resolved later, server-side, at approval.
 *  2. CONFINEMENT. The insert runs under the `public` scope context — the
 *     least-privileged context, matching exactly one policy (the confined
 *     registration_requests INSERT). It reads nothing.
 *
 * Bot confinement (no user-visible difference): a filled honeypot or a
 * sub-threshold form fill-time silently drops the submission — the caller still
 * receives a normal reference, so a bot cannot tell it was dropped.
 */
class RegistrationService
{
    /** Minimum seconds a human takes to fill the form; faster ⇒ bot ⇒ silent drop. */
    private const MIN_FILL_SECONDS = 2;

    /** A form nonce older than this is stale ⇒ silent drop (re-fetch the form). */
    private const MAX_FILL_SECONDS = 3600;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    /**
     * The opt-in listed partner schools for the picker. Exposed by explicit
     * filter — NOT by granting the public context a SELECT (confinement). Unlisted
     * schools are absent by design; their families register direct-to-academy.
     *
     * @return list<array{id: int, name_en: string, name_tc: string, name_sc: string}>
     */
    public function listedSchools(): array
    {
        return DB::table('schools')->where('public_listing', true)->orderBy('name_en')
            ->get(['id', 'name_en', 'name_tc', 'name_sc'])
            ->map(fn ($s) => (array) $s)->all();
    }

    /** A signed, timestamped form nonce; carries the render time for fill-time checking. */
    public function mintNonce(): string
    {
        return Crypt::encryptString((string) now()->timestamp);
    }

    /**
     * Record a registration request. ALWAYS returns the same shape. A bot signal
     * (honeypot / too-fast / stale nonce) returns a reference but writes nothing.
     *
     * @param  array<string, mixed>  $data  validated shape (never existence-checked)
     * @return array{reference: string}
     */
    public function submit(array $data): array
    {
        $reference = strtoupper(Str::random(10));

        if ($this->looksAutomated($data)) {
            return ['reference' => $reference]; // silent drop — indistinguishable to the caller
        }

        $routing = ($data['school_id'] ?? null) ? 'school' : 'academy';
        $id = (string) Str::uuid7();

        $this->scope->setPublic();
        try {
            DB::table('registration_requests')->insert([
                'id' => $id,
                'kind' => $data['kind'],
                'applicant_name' => $data['applicant_name'],
                'applicant_email' => $data['applicant_email'],
                'applicant_phone' => $data['applicant_phone'] ?? null,
                'preferred_language' => $data['preferred_language'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'routing' => $routing,
                'school_id' => $data['school_id'] ?? null,
                'counterpart_email' => $data['counterpart_email'] ?? null,
                'counterpart_name' => $data['counterpart_name'] ?? null,
                'status' => 'submitted',
                'reference' => $reference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Anonymous, but attributed: actor_role 'public' (never a null-role hole).
            $this->audit->record(
                'registration_request', $id, 'registration.submitted',
                toState: 'submitted',
                payloadAfter: ['kind' => $data['kind'], 'routing' => $routing, 'has_counterpart' => ! empty($data['counterpart_email'])],
            );
        } finally {
            $this->scope->reset();
        }

        return ['reference' => $reference];
    }

    /** Honeypot filled, or the form was submitted implausibly fast / with a stale nonce. */
    private function looksAutomated(array $data): bool
    {
        if (! empty($data['website'])) { // honeypot: a hidden field only a bot fills
            return true;
        }
        try {
            $issuedAt = (int) Crypt::decryptString((string) ($data['form_nonce'] ?? ''));
        } catch (\Throwable) {
            return true; // missing/forged/tampered nonce
        }
        $elapsed = now()->timestamp - $issuedAt;

        return $elapsed < self::MIN_FILL_SECONDS || $elapsed > self::MAX_FILL_SECONDS;
    }
}

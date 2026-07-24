<?php

namespace App\Services\Consent;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Uploads\UploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The signing flow (FR036, Spec G4/G8). The three gates — scroll-to-end,
 * affirmation, signature capture — are enforced HERE, against the request's
 * SERVER-recorded event sequence; the UI withholding a button is decoration.
 * The recorded language is the language of the last render the server
 * actually served — the sign call carries no language parameter at all.
 */
class ConsentSigningService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly UploadService $uploads,
    ) {}

    /**
     * Ops issuance (S04A will issue per enrolment through the same path, then
     * narrow the INSERT policy back to system-only — AUDIT §5). Manual issuance
     * always audits the operator AND their reason (Leo ruling 1).
     */
    public function issueRequest(string $templateId, int $programmeId, int $studentId, int $signerId, User $actor, ?string $reason = null): string
    {
        $template = DB::table('consent_templates')->where('id', $templateId)->first();
        $programme = DB::table('programmes')->where('id', $programmeId)->first();
        $student = DB::table('users')->where('id', $studentId)->where('role', 'student')->first();
        $signerIsGuardian = DB::table('guardian_links')
            ->where('guardian_id', $signerId)->where('student_id', $studentId)
            ->where('status', 'active')->exists();
        if ($template === null || $programme === null || $student === null) {
            throw ValidationException::withMessages(['request' => ['Template, programme and student must all exist']]);
        }
        // A request only makes sense for a PUBLISHED programme whose consent
        // section selects THIS template — the same condition under which the
        // bound-party RLS branch lets the signer read the legal text
        $selectedRef = json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'consent')
            ->value('data'), true)['template_ref'] ?? null;
        if ($programme->status !== 'published' || $selectedRef !== $templateId) {
            throw ValidationException::withMessages(['programme' => ['Programme must be published and its consent section must select this template']]);
        }
        if (! $signerIsGuardian) {
            // FR036: a request is only ever addressed to an ACTIVE guardian of the student
            throw ValidationException::withMessages(['signer' => ['Signer must be an active guardian of the student']]);
        }
        $parity = app(ConsentTemplateService::class)->languageParity($templateId);
        if (! $parity['complete'] || $parity['drift']) {
            throw ValidationException::withMessages(['template' => ['Template language versions are incomplete or drifted (OD-20/OD-20a)']]);
        }

        $guardianName = DB::table('users')->where('id', $signerId)->value('name');
        $feeMinor = (int) DB::table('fee_items')->where('programme_id', $programmeId)->sum('amount_minor');
        $id = (string) Str::uuid7();
        DB::table('consent_requests')->insert([
            'id' => $id, 'template_id' => $templateId, 'programme_id' => $programmeId,
            'student_id' => $studentId, 'signer_id' => $signerId, 'status' => 'sent',
            // Frozen NOW, in ops context: rendering is deterministic per request
            // and never varies with the reader's row visibility
            'merge_data' => json_encode([
                'student_name' => $student->name,
                'guardian_name' => $guardianName,
                'programme_name' => $programme->name_en,
                'fee_total' => 'HKD '.number_format($feeMinor / 100, 2),
            ]),
            'event_sequence' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('consent_request', $id, 'consent_request.issued',
            toState: 'sent', reason: $reason,
            payloadAfter: ['student_id' => $studentId, 'signer_id' => $signerId, 'template_id' => $templateId],
            programmeId: $programmeId, actor: $actor);

        return $id;
    }

    /**
     * DERIVED consent status for one of the actor's students (Leo ruling 2):
     * met / outstanding per programme, readable by EVERY active guardian —
     * WITHOUT exposing any other guardian's request row, timestamp or identity.
     * The aggregate must count signed requests the actor's own context rightly
     * cannot see, hence the allowlisted elevation; only booleans leave it.
     *
     * @return array{programme_id: int, student_id: int, consent_met: bool, requires_all_guardians: bool, your_signature_needed: bool}
     */
    public function derivedStatus(int $programmeId, int $studentId, User $guardian): array
    {
        $isGuardian = DB::table('guardian_links')
            ->where('guardian_id', $guardian->id)->where('student_id', $studentId)
            ->where('status', 'active')->exists();
        if (! $isGuardian) {
            abort(404);
        }

        [$met, $requiresAll] = app(\App\Services\Authz\ScopeContext::class)->asSystem(
            'Derived consent status (S03): met/outstanding is an aggregate over ALL guardians\' requests, while RLS correctly hides co-guardians\' rows from each other. Returns booleans only; no row, timestamp or identity leaves the elevation.',
            function () use ($programmeId, $studentId): array {
                $requiresAll = (bool) (json_decode((string) DB::table('wizard_sections')
                    ->where('programme_id', $programmeId)->where('section_key', 'consent')
                    ->value('data'), true)['requires_all_guardians'] ?? false);

                return [$this->consentSatisfied($programmeId, $studentId), $requiresAll];
            },
        );

        // The actor's OWN outstanding request — their own row, already visible
        $ownOpen = DB::table('consent_requests')
            ->where('programme_id', $programmeId)->where('student_id', $studentId)
            ->where('signer_id', $guardian->id)->whereIn('status', ['sent', 'viewed'])->exists();

        return [
            'programme_id' => $programmeId, 'student_id' => $studentId,
            'consent_met' => $met, 'requires_all_guardians' => $requiresAll,
            'your_signature_needed' => $ownOpen && (! $met || $requiresAll),
        ];
    }

    /**
     * Void an issued request whose frozen merge data no longer matches source
     * (Leo item 4). Never a silent re-render — that would break the rendered-
     * hash binding. Any existing signature stays: it is immutable evidence of
     * what WAS signed. Optionally re-issues with a fresh merge snapshot.
     *
     * @return array{voided: string, replacement: string|null}
     */
    public function voidRequest(object $request, string $reason, User $actor, bool $reissue = false): array
    {
        if (! in_array($request->status, ['sent', 'viewed', 'signed'], true)) {
            abort(409, "Consent request is {$request->status} and cannot be voided");
        }

        return DB::transaction(function () use ($request, $reason, $actor, $reissue): array {
            DB::table('consent_requests')->where('id', $request->id)
                ->update(['status' => 'voided', 'updated_at' => now()]);
            $replacementId = $reissue
                ? $this->issueRequest($request->template_id, (int) $request->programme_id,
                    (int) $request->student_id, (int) $request->signer_id, $actor,
                    reason: "re-issue after void of {$request->id}")
                : null;

            $this->audit->record('consent_request', $request->id, 'consent_request.voided',
                fromState: $request->status, toState: 'voided', reason: $reason,
                payloadAfter: ['replacement_request_id' => $replacementId, 'notify_signer' => true],
                programmeId: (int) $request->programme_id, actor: $actor);
            // S09's ladder tells the signer the document changed (card non-scope here)
            \App\Events\ConsentRequestVoided::dispatch($request->id, (int) $request->signer_id, $reason, $replacementId);

            return ['voided' => $request->id, 'replacement' => $replacementId];
        });
    }

    /**
     * Serve the document in $language to the SIGNER and record, server-side,
     * that this language was rendered. Re-rendering in another language
     * invalidates any earlier scroll (the gate checks order).
     *
     * @return array{body_html: string, language: string, template_version_id: string, template_sha256: string, rendered_sha256: string}
     */
    public function renderForSigner(object $request, string $language, User $signer): array
    {
        $this->assertSigner($request, $signer);
        $this->assertOpen($request);
        if (! in_array($language, ConsentTemplateService::LANGUAGES, true)) {
            throw ValidationException::withMessages(['language' => ['Unknown language']]);
        }
        $version = DB::table('consent_template_versions')
            ->where('template_id', $request->template_id)->where('language', $language)
            ->where('status', 'published')->orderByDesc('version')->first();
        if ($version === null) {
            throw ValidationException::withMessages(['language' => ["No published {$language} version"]]);
        }

        $rendered = $this->resolveMergeFields($version->body_html, (array) json_decode($request->merge_data, true));
        $renderedSha = hash('sha256', $rendered);

        $this->appendEvent($request->id, 'rendered', [
            'language' => $language, 'template_version_id' => $version->id,
            'template_sha256' => $version->sha256, 'rendered_sha256' => $renderedSha,
        ]);
        if ($request->status === 'sent') {
            DB::table('consent_requests')->where('id', $request->id)->update(['status' => 'viewed', 'updated_at' => now()]);
            $this->audit->record('consent_request', $request->id, 'consent_request.viewed',
                fromState: 'sent', toState: 'viewed', programmeId: (int) $request->programme_id, actor: $signer);
        }

        return [
            'body_html' => $rendered, 'language' => $language,
            'template_version_id' => $version->id, 'template_sha256' => $version->sha256,
            'rendered_sha256' => $renderedSha,
        ];
    }

    /** The signer reached the end of the document — recorded server-side. */
    public function recordScrolledToEnd(object $request, User $signer): void
    {
        $this->assertSigner($request, $signer);
        $this->assertOpen($request);
        $lastRendered = $this->lastEvent($request->id, 'rendered');
        if ($lastRendered === null) {
            throw ValidationException::withMessages(['scroll' => ['Nothing has been rendered to scroll through']]);
        }
        $this->appendEvent($request->id, 'scrolled', ['language' => $lastRendered['meta']['language']]);
    }

    /**
     * Create the signature. Refuses — at the API, regardless of what any UI
     * did — unless the server-recorded sequence proves render + scroll in the
     * language being signed, the affirmation is present, and a real capture
     * (strokes or typed name) is supplied.
     *
     * @param array{affirmed?: bool, method?: string, strokes?: array, typed_name?: string, image_base64?: string} $input
     */
    public function sign(object $request, array $input, User $signer, string $ip, string $userAgent): string
    {
        $this->assertSigner($request, $signer);
        $this->assertOpen($request);

        // S02A narrow-only link overrides: a guardian_link carrying
        // deny:[consent.sign] blocks signing FOR THAT STUDENT even though the
        // role matrix grants it (the route middleware only sees the matrix)
        if (! app(\App\Services\Authz\PermissionResolver::class)->allowsFor($signer, 'consent.sign', (int) $request->student_id)) {
            $this->audit->record('consent_request', $request->id, 'permission.denied',
                reason: 'guardian_link override denies consent.sign for this student',
                programmeId: (int) $request->programme_id, actor: $signer);
            abort(403, 'Signing is denied for this student by a link-level override');
        }

        $events = $this->events($request->id);
        $renderedIdx = $this->lastIndexOf($events, 'rendered');

        // ── GATE 1: scroll-to-end, server-recorded, AFTER the last render ──
        // (a re-render — e.g. a language switch — invalidates the old scroll)
        $scrolledAfter = $renderedIdx !== null && collect($events)->slice($renderedIdx + 1)
            ->contains(fn ($e) => $e['type'] === 'scrolled');
        if (! $scrolledAfter) {
            throw ValidationException::withMessages([
                'scroll' => ['The document has not been read to the end in the language displayed — no server-recorded scroll-to-end event follows the last render (FR036 gate 1)'],
            ])->status(422);
        }

        // ── GATE 2: explicit affirmation, always required ──
        if (($input['affirmed'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'affirmed' => ['The affirmation of consent has not been given (FR036 gate 2)'],
            ])->status(422);
        }

        // ── GATE 3: a real signature capture — drawn strokes or typed name ──
        $method = $input['method'] ?? null;
        $strokes = $input['strokes'] ?? [];
        $typedName = trim($input['typed_name'] ?? '');
        $captured = ($method === 'drawn' && is_array($strokes) && count($strokes) > 0)
            || ($method === 'typed' && $typedName !== '');
        if (! $captured) {
            throw ValidationException::withMessages([
                'signature' => ['No signature capture — drawn strokes or a typed name is required (FR036 gate 3)'],
            ])->status(422);
        }

        // Language + version + BOTH hashes come from the last SERVER-recorded
        // render — the language actually shown, never a client claim, never a
        // profile preference. The sign call has no language parameter.
        $renderMeta = $events[$renderedIdx]['meta'];
        $version = DB::table('consent_template_versions')->where('id', $renderMeta['template_version_id'])->first();
        if ($version === null || $version->sha256 !== $renderMeta['template_sha256']) {
            throw ValidationException::withMessages(['version' => ['Rendered version hash no longer matches its template version (BI-6)']]);
        }

        $imageUploadId = null;
        if ($method === 'drawn' && ($input['image_base64'] ?? '') !== '') {
            $png = base64_decode($input['image_base64'], true);
            if ($png === false) {
                throw ValidationException::withMessages(['image' => ['Signature image is not valid base64']]);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'sig');
            file_put_contents($tmp, $png);
            // BI-10: the PNG rides the shared upload service like every file
            $imageUploadId = $this->uploads->intake(
                new UploadedFile($tmp, 'signature.png', 'image/png', test: true),
                'consent-signature', $signer,
            )->id;
        }

        return DB::transaction(function () use ($request, $renderMeta, $method, $strokes, $typedName, $imageUploadId, $signer, $ip, $userAgent): string {
            $this->appendEvent($request->id, 'signed', ['language' => $renderMeta['language']]);
            $sequence = $this->events($request->id);

            $id = (string) Str::uuid7();
            DB::table('consent_signatures')->insert([
                'id' => $id, 'request_id' => $request->id,
                'signer_id' => $signer->id, // RLS WITH CHECK signer_id = actor backs this
                'language' => $renderMeta['language'],
                'template_version_id' => $renderMeta['template_version_id'],
                'template_sha256' => $renderMeta['template_sha256'],
                'rendered_sha256' => $renderMeta['rendered_sha256'],
                'method' => $method,
                'signature_payload' => json_encode($method === 'drawn' ? ['strokes' => $strokes] : ['typed_name' => $typedName]),
                'image_upload_id' => $imageUploadId,
                'ip_address' => $ip, 'user_agent' => $userAgent,
                'event_sequence' => json_encode($sequence),
                'signed_at' => now(), 'created_at' => now(),
            ]);
            DB::table('consent_requests')->where('id', $request->id)
                ->update(['status' => 'signed', 'updated_at' => now()]);

            $this->audit->record('consent_request', $request->id, 'consent_request.signed',
                fromState: $request->status, toState: 'signed',
                payloadAfter: [
                    'signature_id' => $id, 'language' => $renderMeta['language'],
                    'template_sha256' => $renderMeta['template_sha256'],
                    'rendered_sha256' => $renderMeta['rendered_sha256'], 'method' => $method,
                ],
                programmeId: (int) $request->programme_id, actor: $signer);

            return $id;
        });
    }

    /**
     * OD-10: is consent satisfied for this student+programme? Any one signed
     * request by default; every active guardian if the programme sets
     * consent_requires_all_guardians. (S04A wires this into enrolment.)
     */
    public function consentSatisfied(int $programmeId, int $studentId): bool
    {
        $flag = (bool) (json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'consent')
            ->value('data'), true)['requires_all_guardians'] ?? false);

        $signedSigners = DB::table('consent_requests')
            ->where('programme_id', $programmeId)->where('student_id', $studentId)
            ->where('status', 'signed')->pluck('signer_id');
        if (! $flag) {
            return $signedSigners->isNotEmpty();
        }
        $activeGuardians = DB::table('guardian_links')
            ->where('student_id', $studentId)->where('status', 'active')->pluck('guardian_id');

        return $activeGuardians->isNotEmpty() && $activeGuardians->diff($signedSigners)->isEmpty();
    }

    // ── internals ──

    private function assertSigner(object $request, User $signer): void
    {
        if ((int) $request->signer_id !== (int) $signer->id) {
            // Not the addressed guardian — 404, indistinguishable from absent
            abort(404);
        }
    }

    private function assertOpen(object $request): void
    {
        if (! in_array($request->status, ['sent', 'viewed'], true)) {
            abort(409, "Consent request is {$request->status} and cannot be acted on");
        }
    }

    private function resolveMergeFields(string $body, array $mergeData): string
    {
        $map = $mergeData + [
            'today' => now()->timezone('Asia/Hong_Kong')->toDateString(),
            'signer_name' => $mergeData['guardian_name'] ?? '',
            'signature' => '<span class="signature-anchor">{{signature}}</span>',
            'signature_date' => '<span class="signature-anchor">{{signature_date}}</span>',
        ];
        foreach ($map as $field => $value) {
            if ($field === 'signature' || $field === 'signature_date') {
                continue; // anchors stay literal until the signed PDF (step 3)
            }
            $body = str_replace('{{'.$field.'}}', e((string) $value), $body);
        }

        return $body;
    }

    /** @return array<int, array{type: string, at: string, meta: array}> */
    private function events(string $requestId): array
    {
        return json_decode((string) DB::table('consent_requests')->where('id', $requestId)->value('event_sequence'), true) ?? [];
    }

    private function appendEvent(string $requestId, string $type, array $meta = []): void
    {
        $events = $this->events($requestId);
        $events[] = ['type' => $type, 'at' => now()->toIso8601String(), 'meta' => $meta];
        DB::table('consent_requests')->where('id', $requestId)
            ->update(['event_sequence' => json_encode($events), 'updated_at' => now()]);
    }

    private function lastEvent(string $requestId, string $type): ?array
    {
        $idx = $this->lastIndexOf($this->events($requestId), $type);

        return $idx !== null ? $this->events($requestId)[$idx] : null;
    }

    /** @param array<int, array{type: string}> $events */
    private function lastIndexOf(array $events, string $type): ?int
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($events[$i]['type'] === $type) {
                return $i;
            }
        }

        return null;
    }
}

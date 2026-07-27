<?php

namespace App\Services\Consent;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Consent templates + language-scoped versions (OD-20/OD-20a, R15, FR035).
 * Each language row hashes independently; publish requires the {{signature}}
 * anchor; parity across the three languages is a programme-publish condition.
 */
class ConsentTemplateService
{
    public const LANGUAGES = ['en', 'zh-TC', 'zh-SC'];

    /** R15 non-legal banner, in-body, per language. */
    public const PLACEHOLDER_BANNERS = [
        'en' => '[PLACEHOLDER — NON-LEGAL, NON-BINDING TEXT. Replaced by Hong Kong lawyer-approved wording before go-live (R15).]',
        'zh-TC' => '[佔位文字 — 非法律、不具約束力之文本。正式上線前將由香港執業律師審定之版本取代(R15)。]',
        'zh-SC' => '[占位文字 — 非法律、不具约束力之文本。正式上线前将由香港执业律师审定之版本取代(R15)。]',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** @param array{name_en: string, name_tc: string, name_sc: string} $names */
    public function createTemplate(array $names, User $actor): string
    {
        $id = (string) Str::uuid7();
        DB::table('consent_templates')->insert(array_merge($names, [
            'id' => $id, 'created_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->audit->record('consent_template', $id, 'consent_template.created',
            payloadAfter: $names, actor: $actor);

        return $id;
    }

    public function draftVersion(string $templateId, string $language, string $bodyHtml, User $actor, bool $isPlaceholder = false): string
    {
        if (! in_array($language, self::LANGUAGES, true)) {
            throw ValidationException::withMessages(['language' => ["Language must be one of: ".implode(', ', self::LANGUAGES)]]);
        }
        $next = (int) DB::table('consent_template_versions')
            ->where('template_id', $templateId)->where('language', $language)
            ->max('version') + 1;

        $id = (string) Str::uuid7();
        DB::table('consent_template_versions')->insert([
            'id' => $id, 'template_id' => $templateId, 'language' => $language,
            'version' => $next, 'body_html' => $bodyHtml, 'status' => 'draft',
            'is_placeholder' => $isPlaceholder, 'created_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('consent_template_version', $id, 'consent_version.drafted',
            toState: "v{$next}/{$language}", actor: $actor);

        return $id;
    }

    public function publishVersion(string $versionId, User $actor, bool $isMaterial = false): void
    {
        $row = DB::table('consent_template_versions')->where('id', $versionId)->first();
        if ($row === null || $row->status !== 'draft') {
            throw ValidationException::withMessages(['version' => ['Only a draft version can be published']]);
        }
        if (! str_contains($row->body_html, '{{signature}}')) {
            // G3: a template cannot be published without a signature anchor
            throw ValidationException::withMessages(['body' => ['Version has no {{signature}} anchor — publishing is blocked (G3)']]);
        }

        DB::table('consent_template_versions')->where('id', $versionId)->update([
            'sha256' => hash('sha256', $row->body_html), // THIS language's own hash (OD-20)
            'status' => 'published', 'published_at' => now(), 'updated_at' => now(),
            'is_material' => $isMaterial,
        ]);
        $this->audit->record('consent_template_version', $versionId, 'consent_version.published',
            fromState: 'draft', toState: 'published',
            payloadAfter: ['language' => $row->language, 'version' => $row->version,
                'is_placeholder' => $row->is_placeholder, 'is_material' => $isMaterial],
            actor: $actor);

        if ($isMaterial) {
            $this->supersedeForLanguage($row->template_id, $row->language, (int) $row->version, $actor);
        }
    }

    /**
     * OD-20a re-consent, LANGUAGE-AWARE: a material change to one language
     * supersedes signed requests whose signature is in THAT language only —
     * untouched languages' signatures stand. Each superseded request gets a
     * fresh one (new merge snapshot). Elevated: the publishing admin's context
     * cannot see the guardians' requests, and must not — only status writes
     * and fresh issuance leave the elevation, each individually audited.
     */
    private function supersedeForLanguage(string $templateId, string $language, int $newVersion, User $actor): void
    {
        app(\App\Services\Authz\ScopeContext::class)->asSystem(
            'OD-20a re-consent fan-out (S03): a material template change must supersede signed requests in the changed language across ALL guardians — rows the publishing admin\'s context rightly cannot read. Status transitions and fresh issuance only, each audited with the publishing admin as actor.',
            function () use ($templateId, $language, $newVersion, $actor): void {
                $stale = DB::table('consent_requests as r')
                    ->join('consent_signatures as s', 's.request_id', '=', 'r.id')
                    ->where('r.template_id', $templateId)->where('r.status', 'signed')
                    ->where('s.language', $language)
                    ->get(['r.id', 'r.programme_id', 'r.student_id', 'r.signer_id', 'r.template_id']);

                foreach ($stale as $request) {
                    DB::table('consent_requests')->where('id', $request->id)
                        ->update(['status' => 'superseded', 'updated_at' => now()]);
                    // S04A: supersession un-satisfies consent — re-evaluate the pool gate
                    \App\Jobs\EvaluateConsentGate::dispatch((int) $request->programme_id,
                        (int) $request->student_id, (int) $actor->id, 'consent superseded (OD-20a)')->afterCommit();
                    $this->audit->record('consent_request', $request->id, 'consent_request.superseded',
                        fromState: 'signed', toState: 'superseded',
                        reason: "material change to {$language} v{$newVersion} (OD-20a)",
                        programmeId: (int) $request->programme_id, actor: $actor);

                    try {
                        app(ConsentSigningService::class)->issueRequest(
                            $request->template_id, (int) $request->programme_id,
                            (int) $request->student_id, (int) $request->signer_id, $actor,
                            reason: "re-consent: material change to {$language} v{$newVersion} (OD-20a)",
                            duringMaterialUpdate: true,
                        );
                    } catch (ValidationException $e) {
                        // e.g. programme meanwhile unpublished — never silent (P4)
                        $this->audit->record('consent_request', $request->id, 'consent_request.reconsent_blocked',
                            reason: implode('; ', array_map(fn ($m) => implode(' ', $m), $e->errors())),
                            programmeId: (int) $request->programme_id, actor: $actor);
                    }
                }
            },
        );
    }

    /**
     * OD-20/OD-20a publish conditions for a selected template:
     * missing language(s) OR unequal max published versions = not publishable.
     *
     * @return array{complete: bool, drift: bool, versions: array<string, int|null>}
     */
    public function languageParity(string $templateId): array
    {
        $versions = [];
        foreach (self::LANGUAGES as $language) {
            $max = DB::table('consent_template_versions')
                ->where('template_id', $templateId)->where('language', $language)
                ->where('status', 'published')->max('version');
            $versions[$language] = $max !== null ? (int) $max : null;
        }
        $present = array_filter($versions, fn ($v) => $v !== null);
        $complete = count($present) === count(self::LANGUAGES);
        $drift = $complete && count(array_unique($present)) > 1;

        return ['complete' => $complete, 'drift' => $drift, 'versions' => $versions];
    }

    /** R15: the placeholder template, all three languages, each with its own hash. */
    public function seedPlaceholder(User $actor): string
    {
        $templateId = $this->createTemplate([
            'name_en' => 'Programme Consent (PLACEHOLDER — R15)',
            'name_tc' => '課程同意書(佔位 — R15)',
            'name_sc' => '课程同意书(占位 — R15)',
        ], $actor);

        foreach (self::LANGUAGES as $language) {
            $banner = self::PLACEHOLDER_BANNERS[$language];
            $body = "<p><strong>{$banner}</strong></p>"
                ."<p>{{student_name}} · {{programme_name}} · {{guardian_name}} · {{fee_total}} · {{today}}</p>"
                ."<p>{$banner}</p>"
                .'<p>{{signature}}</p><p>{{signature_date}} {{signer_name}}</p>';
            $versionId = $this->draftVersion($templateId, $language, $body, $actor, isPlaceholder: true);
            $this->publishVersion($versionId, $actor);
        }

        return $templateId;
    }
}

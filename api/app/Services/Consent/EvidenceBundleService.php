<?php

namespace App\Services\Consent;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * The evidence bundle (FR038): the artifact a legal challenge would demand,
 * assembled so a third party can verify as much as possible FROM THE BUNDLE
 * ALONE. Every hash in the manifest is re-verifiable against bytes shipped in
 * the same bundle; the README states plainly what canNOT be verified without
 * the platform (the timestamp-trust gap, S10).
 */
class EvidenceBundleService
{
    public function __construct(
        private readonly ConsentSigningService $signing,
        private readonly ConsentDocumentService $documents,
    ) {}

    /** Builds the ZIP; returns its absolute path (caller streams + deletes). */
    public function build(string $signatureId): string
    {
        $signature = DB::table('consent_signatures')->where('id', $signatureId)->first()
            ?? throw new RuntimeException('Signature not found');
        $request = DB::table('consent_requests')->where('id', $signature->request_id)->first();
        $version = DB::table('consent_template_versions')->where('id', $signature->template_version_id)->first();
        $document = DB::table('consent_documents')->where('signature_id', $signatureId)->first();
        $signer = DB::table('users')->where('id', $signature->signer_id)->first();
        $student = DB::table('users')->where('id', $request->student_id)->first();
        $programme = DB::table('programmes')->where('id', $request->programme_id)->first();

        // Re-render and REFUSE to export if it no longer reproduces the signed
        // hash — an evidence bundle must never contain an unverifiable claim
        $rendered = $this->signing->renderBody($request, $version->body_html);
        if (hash('sha256', $rendered) !== $signature->rendered_sha256) {
            throw new RuntimeException('Re-render no longer matches rendered_sha256 — bundle refused (BI-6)');
        }
        $pdfBytes = $document !== null ? $this->documents->download($document) : null;

        $auditEvents = DB::table('audit_events')
            ->where('entity_type', 'consent_request')->where('entity_id', $request->id)
            ->orderBy('occurred_at')
            ->get(['event_id', 'occurred_at', 'action', 'actor_id', 'actor_role', 'from_state', 'to_state', 'reason', 'ip_address']);

        $manifest = [
            'bundle_generated_at' => now()->toIso8601String(),
            'signature' => [
                'id' => $signature->id, 'request_id' => $signature->request_id,
                'signer' => ['id' => $signature->signer_id, 'name' => $signer->name],
                'student' => ['id' => $request->student_id, 'name' => $student->name],
                'programme' => ['id' => $request->programme_id, 'code' => $programme->code],
                'language_signed' => $signature->language,
                'method' => $signature->method,
                'signed_at_utc' => (string) $signature->signed_at,
                'ip_address' => $signature->ip_address, 'user_agent' => $signature->user_agent,
                'signature_payload' => json_decode($signature->signature_payload),
            ],
            'template_version' => [
                'id' => $version->id, 'version' => $version->version, 'language' => $version->language,
                'published_at' => (string) $version->published_at, 'is_placeholder' => (bool) $version->is_placeholder,
            ],
            'hashes' => [
                'template_sha256' => ['value' => $signature->template_sha256, 'verify_against' => 'template.html'],
                'rendered_sha256' => ['value' => $signature->rendered_sha256, 'verify_against' => 'rendered.html'],
                'pdf_sha256' => $document !== null
                    ? ['value' => $document->pdf_sha256, 'verify_against' => 'consent.pdf']
                    : null,
            ],
            'pdf_generator' => $document?->generator,
            'event_sequence' => json_decode($signature->event_sequence),
        ];

        $path = tempnam(sys_get_temp_dir(), 'bundle').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('template.html', $version->body_html);
        $zip->addFromString('rendered.html', $rendered);
        if ($pdfBytes !== null) {
            $zip->addFromString('consent.pdf', $pdfBytes);
        }
        $zip->addFromString('audit-events.json', json_encode($auditEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('README.txt', $this->readme());
        $zip->close();

        return $path;
    }

    private function readme(): string
    {
        return <<<'TXT'
            CONSENT EVIDENCE BUNDLE — VERIFICATION GUIDE
            ============================================

            VERIFIABLE FROM THIS BUNDLE ALONE (no platform access needed):
            1. sha256(template.html)  == manifest.hashes.template_sha256
               The exact legal text of the template version, in the language signed.
            2. sha256(rendered.html)  == manifest.hashes.rendered_sha256
               The exact document shown to the signer (template + resolved merge
               fields). Inspect that it is a faithful merge of template.html.
            3. sha256(consent.pdf)    == manifest.hashes.pdf_sha256
               The signed PDF, whose final page is the audit certificate carrying
               the same two hashes, the server-recorded event sequence, and the
               generator version. Fonts are embedded; it renders identically
               without platform access.
            4. manifest.event_sequence — server-recorded render/scroll/sign events
               with per-event language and hashes; internally consistent ordering
               (a scroll-to-end follows the render of the language signed).
            5. audit-events.json — the request's full audit trail (issue, view,
               sign, and any supersede/void), with actor identities.

            NOT VERIFIABLE FROM THIS BUNDLE ALONE — KNOWN GAPS, STATED PLAINLY:
            A. TIME. All timestamps are the platform's server clock. Nothing in
               this bundle cryptographically anchors any hash to a moment in time
               or proves the bundle's contents were not assembled later. An
               RFC-3161 trusted timestamp (or external hash-chain anchoring) is
               the planned S10 decision; it can only protect signatures made
               after it is adopted.
            B. SIGNER IDENTITY. The signature is bound to an authenticated
               platform account (credentials + audited session), not to a
               qualified certificate held by the signer. Corroborating the
               account-to-person link requires platform records (invitation
               chain, auth log).
            C. DATABASE STATE. That the signature row is INSERT-only-protected,
               that audit_events is append-only, and that this bundle matches
               the live rows can only be attested by inspecting the platform.
            TXT;
    }
}

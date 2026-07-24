<?php

namespace App\Services\Consent;

use App\Models\Upload;
use App\Services\Uploads\UploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use RuntimeException;

/**
 * Signed PDF + audit certificate page (FR038), PDF/A-1b. The PDF re-renders
 * the document from the same deterministic inputs the signer saw and REFUSES
 * to generate if the re-render's hash does not equal the signature's
 * rendered_sha256 — evidence is never generated from drifted inputs.
 *
 * mPDF hardening (Leo conditions, S03-3): merge values are HTML-escaped
 * upstream (ConsentSigningService::renderBody); this service additionally
 * strips asset-fetching markup, and the mPDF instance is built with an EMPTY
 * stream-wrapper whitelist so file:// and remote fetches are refused even if
 * markup slipped through. CJK renders from the embedded, subset Sun-ExtA TTF —
 * never the non-embedded Adobe CJK mode. The generator version is recorded on
 * the certificate page and the consent_documents row.
 */
class ConsentDocumentService
{
    public const GENERATOR = 'mpdf/mpdf '.Mpdf::VERSION;

    public function __construct(
        private readonly ConsentSigningService $signing,
        private readonly UploadService $uploads,
    ) {}

    /** Runs in the queued job's system context. */
    public function generate(string $signatureId): string
    {
        $signature = DB::table('consent_signatures')->where('id', $signatureId)->first()
            ?? throw new RuntimeException("Signature {$signatureId} not found");
        if (DB::table('consent_documents')->where('signature_id', $signatureId)->exists()) {
            return DB::table('consent_documents')->where('signature_id', $signatureId)->value('id'); // idempotent
        }
        $request = DB::table('consent_requests')->where('id', $signature->request_id)->first();
        $version = DB::table('consent_template_versions')->where('id', $signature->template_version_id)->first();
        $signer = DB::table('users')->where('id', $signature->signer_id)->first();

        // BI-6 discipline before any PDF exists: the re-render must reproduce
        // the exact bytes the guardian saw, or we stop loudly.
        $rendered = $this->signing->renderBody($request, $version->body_html);
        if (hash('sha256', $rendered) !== $signature->rendered_sha256) {
            throw new RuntimeException(
                "Re-render hash mismatch for signature {$signatureId} — refusing to generate evidence from drifted inputs (BI-6)"
            );
        }
        if ($version->sha256 !== $signature->template_sha256) {
            throw new RuntimeException("Template version hash mismatch for signature {$signatureId} (BI-6)");
        }

        $html = $this->substituteSignatureBlock($this->sanitizeForPdf($rendered), $signature, $signer);
        $pdfBytes = $this->renderPdf($html, $this->certificateHtml($signature, $request, $version, $signer), $signature->language);

        $tmp = tempnam(sys_get_temp_dir(), 'consentpdf');
        file_put_contents($tmp, $pdfBytes);
        $upload = $this->uploads->intake(
            new UploadedFile($tmp, "consent-{$signature->request_id}.pdf", 'application/pdf', test: true),
            'consent-document',
        );

        $id = (string) Str::uuid7();
        DB::table('consent_documents')->insert([
            'id' => $id, 'signature_id' => $signature->id, 'request_id' => $signature->request_id,
            'signer_id' => $signature->signer_id, 'language' => $signature->language,
            'pdf_upload_id' => $upload->id, 'pdf_sha256' => hash('sha256', $pdfBytes),
            'generator' => self::GENERATOR, 'created_at' => now(),
        ]);

        return $id;
    }

    /** Streamed bytes for an authorised reader (authorisation = the cd_read policy upstream). */
    public function download(object $document): string
    {
        // The upload row is system-owned storage detail; who may download was
        // already decided by consent_documents RLS before we get here.
        $upload = app(\App\Services\Authz\ScopeContext::class)->asSystem(
            'Consent document download (S03): the signed-PDF upload row is system-owned storage; read authorisation was already decided by the consent_documents RLS read set for the requesting session.',
            fn () => Upload::query()->find($document->pdf_upload_id),
        );
        if ($upload === null || $upload->status !== 'clean') {
            // BI-10: invisible until the scan passes — no exceptions for our own files
            abort(409, 'Document is not yet available');
        }

        return $this->uploads->contents($upload);
    }

    /**
     * Defense in depth behind the upstream escaping: strip every asset-fetching
     * or style-injection construct. Consent documents are text; anything that
     * could make mPDF fetch has no business here.
     */
    public function sanitizeForPdf(string $html): string
    {
        $html = preg_replace('#<\s*(img|iframe|object|embed|link|style|script|svg|video|audio|source)\b[^>]*>#i', '', $html);
        $html = preg_replace('#</\s*(iframe|object|style|script|svg|video|audio)\s*>#i', '', $html);
        $html = preg_replace('#style\s*=\s*("[^"]*url\s*\([^"]*"|\'[^\']*url\s*\([^\']*\')#i', '', $html);
        $html = str_ireplace('@import', '&#64;import', $html);

        return $html;
    }

    /** The anchors become the signature visual + date — AFTER hash verification. */
    private function substituteSignatureBlock(string $html, object $signature, object $signer): string
    {
        $payload = (array) json_decode($signature->signature_payload, true);
        $visual = $signature->method === 'drawn'
            ? '<img src="'.$this->strokesToPngDataUri($payload['strokes'] ?? []).'" style="width:220px" />'
            : '<span style="font-size:1.4em; font-style:italic">'.e($payload['typed_name'] ?? '').'</span>';
        $signedDate = Carbon::parse($signature->signed_at)->timezone('Asia/Hong_Kong')->format('Y-m-d H:i');

        return str_replace(
            ['{{signature}}', '{{signature_date}}'],
            [$visual, e($signedDate).' HKT'],
            $html,
        );
    }

    /** Stroke vectors → PNG (GD) → data URI. No file paths, no fetching. */
    public function strokesToPngDataUri(array $strokes): string
    {
        $im = imagecreatetruecolor(440, 160);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $ink = imagecolorallocate($im, 24, 22, 34);
        imagesetthickness($im, 3);
        foreach ($strokes as $stroke) {
            for ($i = 1, $n = count($stroke); $i < $n; $i++) {
                [$x1, $y1] = $stroke[$i - 1];
                [$x2, $y2] = $stroke[$i];
                imageline($im, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $ink);
            }
            if (count($stroke) === 1) {
                imagefilledellipse($im, (int) $stroke[0][0], (int) $stroke[0][1], 4, 4, $ink);
            }
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** The audit certificate page (FR038): what, who, when, how — and with what. */
    public function certificateHtml(object $signature, object $request, object $version, object $signer): string
    {
        $events = '';
        foreach ((array) json_decode($signature->event_sequence, true) as $event) {
            $meta = $event['meta'] ?? [];
            $events .= '<tr><td>'.e($event['at']).'</td><td>'.e($event['type']).'</td><td>'
                .e(($meta['language'] ?? '').(isset($meta['rendered_sha256']) ? ' · rendered '.substr($meta['rendered_sha256'], 0, 16).'…' : ''))
                .'</td></tr>';
        }
        $placeholder = $version->is_placeholder
            ? '<p style="border:2px solid #B03030; padding:6px"><strong>R15: this document was generated from PLACEHOLDER, NON-LEGAL, NON-BINDING template text.</strong></p>'
            : '';

        return <<<HTML
            <h2>Audit Certificate — Consent Signature</h2>
            {$placeholder}
            <table style="width:100%" cellpadding="3">
            <tr><td>Signature</td><td>{$signature->id}</td></tr>
            <tr><td>Request</td><td>{$signature->request_id}</td></tr>
            <tr><td>Signer</td><td>{$this->e($signer->name)} (user {$signature->signer_id})</td></tr>
            <tr><td>Language signed</td><td>{$signature->language}</td></tr>
            <tr><td>Template version</td><td>{$version->id} (v{$version->version}, {$version->language})</td></tr>
            <tr><td>Template SHA-256</td><td style="font-size:.8em">{$signature->template_sha256}</td></tr>
            <tr><td>Rendered-document SHA-256</td><td style="font-size:.8em">{$signature->rendered_sha256}</td></tr>
            <tr><td>Method</td><td>{$signature->method}</td></tr>
            <tr><td>Signed at (UTC)</td><td>{$signature->signed_at}</td></tr>
            <tr><td>IP / User agent</td><td style="font-size:.8em">{$this->e($signature->ip_address)} · {$this->e($signature->user_agent)}</td></tr>
            </table>
            <h3>Server-recorded event sequence</h3>
            <table style="width:100%" cellpadding="3">{$events}</table>
            <p style="font-size:.8em">Generated by {$this->e(self::GENERATOR)} at {$this->e(now()->toIso8601String())} ·
            PDF/A-1b · Academy-issued; no co-branding, no external signatories.</p>
            HTML;
    }

    private function e(?string $value): string
    {
        return e((string) $value);
    }

    public function renderPdf(string $documentHtml, string $certificateHtml, string $language): string
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            // PDF/A-1b: archival, self-contained (Leo condition 3)
            'PDFA' => true,
            'PDFAauto' => true,
            // NO stream wrapper is whitelisted: file://, http(s)://, phar://
            // fetches are all refused (Leo condition 1). data: URIs (our own
            // signature PNG) carry no wrapper and are unaffected.
            'whitelistStreamWrappers' => [],
            'useSubstitutions' => true, // glyph fallback runs through EMBEDDED fonts only
        ]);
        // Embedded-subset fonts only (Leo condition 2): DejaVu for Latin,
        // Sun-ExtA (bundled TTF, TC+SC coverage) for CJK. Never Adobe CJK.
        $font = in_array($language, ['zh-TC', 'zh-SC'], true) ? 'sun-exta' : 'dejavusans';
        $mpdf->WriteHTML("<style>body { font-family: {$font}, dejavusans, sun-exta; }</style>");
        $mpdf->WriteHTML($documentHtml);
        $mpdf->AddPage();
        $mpdf->WriteHTML($certificateHtml);

        return $mpdf->OutputBinaryData();
    }
}

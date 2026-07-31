<?php

// Shared upload service (2.12 / Spec O2) — the single file-intake pipeline (BI-10).
// Per-context MIME allow-lists and size caps. Every future upload surface names a
// context here; no context ever bypasses the service.
return [
    'disk' => env('UPLOADS_DISK', 'local'),

    'paths' => [
        'pending' => 'uploads/pending',
        'clean' => 'uploads/clean',
        'quarantine' => 'uploads/quarantine',
    ],

    // O2: images jpg/png/webp 5 MB · documents pdf 15 MB · evidence pdf/jpg/png 15 MB
    'contexts' => [
        'image' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_bytes' => 5 * 1024 * 1024,
        ],
        'document' => [
            'mimes' => ['application/pdf'],
            'max_bytes' => 15 * 1024 * 1024,
        ],
        'evidence' => [
            'mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
            'max_bytes' => 15 * 1024 * 1024,
        ],
        // S03: drawn-signature PNG captured in the signing flow (G4)
        'consent-signature' => [
            'mimes' => ['image/png'],
            'max_bytes' => 2 * 1024 * 1024,
        ],
        // S03: generated signed PDF + audit certificate (FR038)
        'consent-document' => [
            'mimes' => ['application/pdf'],
            'max_bytes' => 15 * 1024 * 1024,
        ],
        // S04E: bulk-enrolment roll CSV (Phase 1 = CSV only; xlsx deferred).
        // finfo resolves plain CSV as text/plain or text/csv depending on
        // content; both are allow-listed and the file is verified structurally
        // at parse. Small cap — a roll of names, not a document.
        'batch-csv' => [
            'mimes' => ['text/csv', 'text/plain', 'application/csv'],
            'max_bytes' => 2 * 1024 * 1024,
        ],
    ],

    // S04E: hard row-count cap enforced at parse (a roll, not a dataset).
    'batch_csv_max_rows' => 5000,

    'clamav' => [
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout_seconds' => (int) env('CLAMAV_TIMEOUT', 30),
    ],
];

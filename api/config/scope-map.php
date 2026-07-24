<?php

// The table classification map (S02A step 3, Leo amendment 2). EVERY table in
// the schema must appear here; the nightly scope-coverage assertion FAILS on:
//   - any table absent from this map (the sprint adding a table classifies it),
//   - any 'scoped' table lacking RLS (relrowsecurity ∧ relforcerowsecurity ∧ ≥1 policy),
//   - any 'global' entry whose justification is empty, short or placeholder
//     (Leo: 'global' is the escape hatch — the reasoning goes on the record at
//     the moment of the decision, not reconstructed later).
return [
    // RLS-enforced; policies keyed on the app.* session context
    'scoped' => [
        // Reclassified from global at the S02A gate review (Leo item 2): a
        // school_admin session could read every account platform-wide. Policies
        // admit ONLY the auth-bootstrap phase (empty context — Sanctum/guest
        // flows precede context by construction), system, self, academy staff,
        // and link-derived scope. Residual bootstrap window: AUDIT §5.
        'users',
        'personal_access_tokens',
        'guardian_links',
        'school_links',
        'teacher_links',
        'school_admin_links',
        'admin_capabilities',
        'uploads',
        'audit_events',
        'guardian_replacement_exceptions',
    ],

    // Deliberately unscoped — each with its recorded reason
    'global' => [
        'password_reset_tokens' => 'Guest flow by design (2.11): the emailed token IS the access control; rows are (email, hashed token, timestamp) only.',
        'invitations' => 'Guest acceptance precedes authentication by design (2.11): the sha256 single-use token IS the access control. Rows carry invitee email and role only; issuance is permission-gated at the route.',
        'pairing_codes' => 'The 6-char code IS the bearer secret (B4): redemption happens before any link exists, so a link-derived policy cannot admit the legitimate redeemer. Rows carry only student linkage; failure ledger and throttles guard brute force (2.13).',
        'schools' => 'Reference data: trilingual school names only, no personal data; write access is permission-gated (configuration.manage).',
        'programmes' => 'Academy-wide catalogue: visibility rule is AUTHENTICATION not school membership (L4 members-only catalogue, enforced at routes); rows contain configuration, no personal data.',
        'programme_versions' => 'Immutable config snapshots of the global catalogue (D5); INSERT-only at the DB; no personal data.',
        'roles' => 'Fixed platform taxonomy (A2/B1); six rows, no personal data.',
        'permissions' => 'Fixed permission catalogue; no personal data.',
        'role_permissions' => 'The seeded matrix, guarded by authz.permission_matrix; no personal data.',
        'capability_permissions' => 'The seeded capability grid, guarded by authz.permission_matrix and authz.consent_sign_exclusive; no personal data.',
        'pairing_code_failures' => 'Brute-force counter keyed by code string (2.13); contains no personal data and must be writable from failing (unauthorised) request paths.',
        'reconciliation_log' => 'Assertion run records (SR010); no personal data; read surface is permission-gated (audit_read).',
    ],

    // Framework plumbing — no domain data
    'infrastructure' => [
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
    ],
];

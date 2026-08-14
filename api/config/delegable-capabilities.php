<?php

// A-1 — THE DELEGABLE-CAPABILITY CATALOGUE (the delegation safety spine).
//
// For EVERY permission in config/permission-matrix.php 'permissions', this file states whether it may be
// DELEGATED to an edge operator (a school admin or teacher, via the future grant map) or is NEVER delegable.
//
// This catalogue GATES the future delegation map (A-2 grant tables + A-4 policy arms): a capability absent
// from 'delegable' can NEVER be granted to a school/teacher — enforced two ways that both cite this file:
//   1. A-2 grant validation will reject any grant of a key not in 'delegable'.
//   2. The 'authz.delegable_catalogue_integrity' nightly assertion (this card) fails loud on any drift:
//      an orphan/typo key, an unclassified permission, a permission in both sets, a missing hard-NEVER, or
//      a dropped formed-team marker.
//
// The 'never' set is the HARD safety spine — immovable by construction:
//   consent.sign        — legal guardian act (FR036, ETO Cap. 553, BI-6); a staff-signable consent proves nothing.
//   finance.record      — the recorder leg of BI-9 four-eyes; money recording is academy-settled, never an edge write.
//   finance.confirm     — the confirmer leg of BI-9 four-eyes.
//   capabilities.grant  — the grant-of-grants; delegating it would let an edge operator mint authority.
//   configuration.manage— programme/config authority (academy only).
//   operations.manage   — academy-wide oversight power (approvals/oversight), an academy_admin capability.
//   audit.read          — the cross-tenant immutable audit trail; academy oversight only.
//   member_directory.view — delegating it adds an edge holder, breaching the authz.member_directory_exclusive control.
//
// This generalises the ad-hoc school-scoped elevations already in config/scope-elevations.php
// (BulkStudentCreation, enrolment_batches, consolidated invoices) — A-1 does NOT touch those; it is the
// catalogue those flows' successors (A-2/A-4) will validate against.
return [
    // Safe to delegate to a school/teacher edge operator (reads + the sanctioned edge writes).
    'delegable' => [
        'student_records.view',
        'student_records.manage',
        'consent.view',
        'enrolment.view',
        'enrolment.create',
        'finance.view',
        'teams.view',
        'teams.approve',
        'events.view',
        'events.rsvp',
    ],

    // NEVER delegable — the hard safety spine (see header for the per-key rationale).
    'never' => [
        'consent.sign',
        'finance.record',
        'finance.confirm',
        'capabilities.grant',
        'configuration.manage',
        'operations.manage',
        'audit.read',
        'member_directory.view',
    ],

    // Reserved never-markers — NOT matrix permissions yet (no permission exists), so they are exempt from the
    // orphan-check but MUST be present (the assertion guards them). They record a platform-only invariant now
    // so the concept is born never-delegable the moment its permission is introduced (A-2/A-4 or later).
    'never_reserved' => [
        // Mutating a FORMED team's membership (成團) is platform-only — never an edge operator's act. teams.approve
        // (delegable) is the approval of formation; membership mutation AFTER 成團 is a different, reserved act.
        'teams.formed_membership_mutation' => 'platform_only',
    ],
];

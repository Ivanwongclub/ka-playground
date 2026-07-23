<?php

// THE permission matrix — single source of truth (Spec B7 layer 1, OD-1, OD-17).
// The migration seeds the database from this file; the S01 reconciliation probe
// compares the database back against it, so post-seed drift is caught nightly.
// Per-link overrides (B7 layer 2) ride on the link entities (S01 step 2).
// Scoping (own-child, own-student) is applied at the query layer per sprint.
return [
    // Fixed catalogue. Adding a permission is a migration + matrix edit, never runtime.
    'permissions' => [
        'student_records.view',
        'student_records.manage',
        'consent.view',
        'consent.sign',
        'enrolment.view',
        'enrolment.create',
        'finance.view',
        'finance.record',
        'finance.confirm',
        'teams.view',
        'teams.approve',
        'events.view',
        'events.rsvp',
        'directory.view',
        'audit.read',
        'configuration.manage',
        'operations.manage',
        'capabilities.grant',
    ],

    // Six roles (OD-1), never stacked (Spec B1). Role defaults = B7 layer 1.
    'roles' => [
        'student' => [
            'student_records.view', 'consent.view', 'enrolment.view',
            'teams.view', 'events.view', 'events.rsvp',
        ],
        'guardian' => [
            'student_records.view', 'consent.view', 'consent.sign',
            'enrolment.view', 'enrolment.create', 'finance.view', 'events.view',
        ],
        'teacher' => [
            'student_records.view', 'teams.view', 'teams.approve',
            'enrolment.view', 'events.view',
        ],
        'school_admin' => [
            'student_records.view', 'student_records.manage', 'consent.view',
            'enrolment.view', 'enrolment.create', 'finance.view',
            'teams.view', 'events.view',
        ],
        // Academy Administrator: thin base; power arrives via capability groups (OD-17)
        'academy_admin' => [
            'events.view', 'directory.view',
        ],
        // Member (OD-1): events, RSVP, directory — NOTHING else. The absence of
        // every student_records/consent/enrolment/finance permission IS the control.
        'member' => [
            'events.view', 'events.rsvp', 'directory.view',
        ],
    ],

    // Capability groups (OD-17) — qualify an academy_admin, never blur identity
    'capabilities' => [
        'super_admin' => '*', // all permissions, incl. capabilities.grant
        'configuration' => ['configuration.manage'],
        'finance' => ['finance.view', 'finance.record', 'finance.confirm'],
        'operations' => [
            'operations.manage', 'student_records.view', 'student_records.manage',
            'consent.view', 'enrolment.view', 'enrolment.create',
            'teams.view', 'teams.approve', 'events.view',
        ],
        'audit_read' => ['audit.read'],
    ],
];

<?php

// The asSystem() allowlist (Leo, S02A gate review). Every sanctioned elevation
// is declared here: call site => the reason it exists. asSystem() REFUSES any
// call site not listed (runtime LogicException) and audits every elevation.
// A phpunit scan additionally fails when the code contains an asSystem() call
// site absent from this list — by S07 the justifications are on the record,
// not in anyone's memory.
return [
    'App\Services\Identity\LinkRevocationService::revoke' => 'Sole-guardian integrity check (2.2): sole-ness must count ALL active links, while RLS correctly hides co-guardians from each other. Read-only count; result never exposes the hidden rows.',

    'App\Http\Controllers\LinkController::requestByEmail' => 'B4 parent-initiated flow: pre-link student lookup by exact email — the target is by definition outside the guardian\'s scope until the link exists. Response is identical whether or not the account exists; only a pending link (student-confirmable) results. Also counts the student\'s existing guardians (OD-24 second-guardian check) which are likewise outside scope.',
    'App\Services\Identity\PairingService::redeem' => 'OD-24 second-guardian check (pairing redeem): a would-be additional guardian is outside the scope of the student\'s existing guardians, so counting them requires system context. Read-only; refuses a non-vouch second-guardian self-add.',
    'App\Services\Identity\BulkStudentCreationService::create' => 'Bulk student creation (OD-23 pt 5 / OD-50): a school admin creates the students on its OWN roll. The accounts and their school affiliations are outside the admin\'s derived scope until they exist, and the active school_links write is system-context by construction (S04D hardening). Per-row roll authority is enforced from the admin\'s own school_admin_links; mints born-unverified via mintPendingActivation; audits user.created + school_link.created(active) to the admin.',

    'App\Http\Controllers\EnrolmentBatchController::upload' => 'S04E STEP 1 batch creation (Spec Part H): records the enrolment_batches row for a school-wide operation (system-write by construction) and chains the parse off the scan verdict. Roll authority was checked at the edge; per-row authority is create()\'s at commit.',

    'App\Jobs\ValidateEnrolmentBatch::handle' => 'S04E STEP 1 batch validation (Spec Part H): reads the batch-csv upload verdict and, only on CLEAN, parses the roll into enrolment_batch_rows dispositions. The batch/upload/rows are a school-wide operation outside any single actor\'s derived scope and are system-write by construction. The parse reads bytes via UploadService::contents(), which refuses anything not CLEAN (BI-10). DRY RUN — no account or enrolment created.',

    'App\Services\Enrolment\EnrolmentBatchCsvParser::parse' => 'S04E STEP 1 CSV parse (Spec Part H / OD-25): writes enrolment_batch_rows dispositions for a scan-clean roll. The batch and its rows are outside any single actor\'s derived scope (they are a school-wide operation) and the tables are system-write by construction; disposition reads users/school_links across the school roll. DRY RUN — creates no account and no enrolment.',

    'App\Services\Enrolment\EnrolmentBatchCommitService::commit' => 'S04E STEP 2 batch commit (Spec Part H / OD-31): drives the existing enrolment machinery row-by-row from a validated batch. The batch/rows/enrolments are a school-wide operation outside any single actor\'s derived scope; enrolment inserts are system-context here (the school admin is not the student\'s guardian). Re-evaluates guardian eligibility LIVE; reuses BulkStudentCreationService::create + EnrolmentService::create; DRY-of-orders (intent only, OD-31).',

    'App\Http\Controllers\LinkController::schoolVouch' => 'School vouch (OD-30): the vouching school admin\'s single audited act creates an ACTIVE guardian-student link for a student verified ON THEIR ROLL (the in-school check precedes this). The guardian and the activation are outside the admin\'s derived scope; the active write is system-context by construction. Writes the link, its to_state=active audit, and OD-24 visibility records to every existing guardian (never silent).',
    // OD-27: GuardianStudentService::createStudent RETIRED in S04C STEP 4 — the
    // guardian-creates-student path is replaced by self-registration + approval.

    'App\Services\Identity\RegistrationApprovalService::approve' => 'Registration approval (OD-23/OD-29): the reviewer creates an account for a person who is BY DEFINITION outside the reviewer\'s scope until it exists (INSERT..RETURNING checks the new row against SELECT policies). Creates exactly one UNVERIFIED account + one single-use activation token, updates the request, all audited to the reviewer.',

    'App\Services\Identity\LinkageService::materialiseFor' => 'Held-link materialisation (OD-23, Leo 1a): a relationship CLAIMED against an address on a registration form materialises into a pending_approval link only once that address proves control of itself (verification). System-context: the claimant is outside the just-verified account\'s scope, and held_links are system-write by construction. Creates pending links only — never active.',
    'App\Services\Identity\LinkageService::approveLink' => 'Guardian-link approval (OD-23/2.30 · FLAG #2): the admin\'s decision — separate from approving either person — activates the relationship. CAS pending_approval→active; writes the to_state=\'active\' audit that S06 requires_all consent hardening depends on. Elevated because a not-yet-affiliated student is outside the reviewer\'s derived scope.',

    'App\Services\Identity\AuthService::login' => 'Credential-verified token issuance: login is an auth-bootstrap act regardless of any pre-existing session (account switching); the token belongs to the just-verified credential holder, not the ambient actor.',

    'App\Services\Identity\InvitationService::accept' => 'Invitation acceptance is a pre-authentication bootstrap act by design (2.11): creates the invited account and activates any school-vouched teacher affiliation — single-use token-gated writes no scoped context could perform.',

    'App\Services\Consent\ConsentSigningService::derivedStatus' => 'Derived consent status (S03): met/outstanding is an aggregate over ALL guardians\' requests, while RLS correctly hides co-guardians\' rows from each other. Returns booleans only; no row, timestamp or identity leaves the elevation.',

    'App\Services\Consent\ConsentDocumentService::download' => 'Consent document download (S03): the signed-PDF upload row is system-owned storage; read authorisation was already decided by the consent_documents RLS read set for the requesting session.',

    'App\Services\Consent\ConsentTemplateService::supersedeForLanguage' => 'OD-20a re-consent fan-out (S03): a material template change must supersede signed requests in the changed language across ALL guardians — rows the publishing admin\'s context rightly cannot read. Status transitions and fresh issuance only, each audited with the publishing admin as actor.',
    'App\Services\Money\PaymentLinkService::resolve' => 'Anonymous payment-link resolution (OD-44): the viewer holds only the bearer token; no session, no context. Reads exactly one frozen-payload row by sha256 token hash; initials-only, no other order data reachable.',

    'App\Services\Money\PaymentLinkService::confirmPayment' => 'Anonymous payment-link confirmation (OD-44): the payer holds only the bearer token. Atomic claim (active→paying CAS) serialises concurrent confirmers; provider self-confirms (OD-47); writes payment + order transition + link death, all audited.',
    'App\Services\Money\ManualPaymentService::confirm' => 'Manual payment BI-10 gate (S04B): confirmation must wait until every evidence upload is scan-clean. Scan status is a system-integrity fact; the confirmer\'s authorisation is already established by finance.confirm + BI-9. Reads only uploads.status for this payment\'s evidence; no upload content or other row leaves the elevation.',
    'App\Services\Teams\FormationService::addMember' => 'Team formation transition (S05): joining a team moves the member\'s enrolment in_pool → teamed. The enr_update policy restricts state writes to system (S04A); the joining student\'s authority was established by the pooled-enrolment + lobby-eligibility checks in their own context immediately prior. Transitions exactly this one enrolment.',
    'App\Services\Programmes\WizardService::seedCapacity' => 'Programme capacity seed (S05-2): publish seeds the seat counter from eligibility.capacity with claimed=0. programme_capacity is a system-only table; this is the one insert of the row, inside the publish transaction. Publish authority was established by the route before this call.',

    'App\Services\Programmes\WizardService::saveSection' => 'Programme capacity edit (S05-2): the seat counter is a system-only table (claimed moves only through 成團); this raises/lowers the CAPACITY column after the OD-31 lower-below-claimed guard, never claimed. Config authority was established by the wizard route before this call.',

    'App\\Services\\Teams\\TeamConfirmationService::submit' => 'Team submit transition (S05): the submitter moves their own forming team to submitted; teams.status is a system-only write (S04A state-machine discipline), the submitter authority was just checked.',

    'App\\Services\\Teams\\TeamConfirmationService::confirm' => 'Team 成團 confirmation (S05): the whole seat-claim transaction is a system state-machine op — FOR SHARE on members\' consent (+guardian_links), FOR UPDATE on programme_capacity, teamed→confirmed, one payment_obligation per member. The approver\'s authority (OD-39) was established before the elevation; only the members\' own rows are touched.',

    'App\Services\Teams\FormationDeadlineService::run' => 'Formation deadline job (S05-3, OD-33): at the deadline the SYSTEM auto-submits size-compliant forming teams and raises deadline_noncompliant exceptions for the rest. teams.status and team_exceptions are system-only writes; this is the scheduled actor. Reads and writes only rows of past-deadline programmes.',

    'App\Services\Teams\MatchingService::match' => 'Deadline matching — MATCH (S05-3, OD-35): an admin places an unplaced student into an under-strength team; the enrolment moves in_pool → teamed (system-only) and a team_member is inserted. The admin authority and lobby eligibility are checked before the elevation; exactly this one enrolment/team is touched.',

    'App\Services\Teams\MatchingService::roll' => 'Deadline matching — ROLL (S05-3, OD-35): an admin parks an unplaced student as a pending roll-forward exception with a 90-day auto-refund backstop. The enrolment stays in_pool; only a team_exceptions row is written (system-only). Admin authority checked before the elevation.',

    'App\Services\Teams\MatchingService::release' => 'Deadline matching — RELEASE (S05-3, OD-35): an admin releases an unplaced student; the enrolment moves in_pool → released (system-only) and any open parking exception is resolved. Admin authority checked before the elevation.',

    'App\Services\Teams\ParkingBackstopService::run' => 'Parking backstop (S05-3, OD-35): the SYSTEM force-resolves parked roll-forwards past their 90-day window — full auto-refund (origin=backstop_auto, out of BI-9 per OD-47) of any paid order, then enrolment in_pool → released, then the exception auto_released. Scheduled actor; touches only expired parked rows and their own orders/enrolments.',

    'App\Services\Teams\LapseDetectionService::run' => 'Lapse-detection job (S05-4, OD-45): the SYSTEM scans family-paid unpaid orders past payment_due_at + grace and, for each, writes a lapse audit, suspends the member on team_members, and raises an FR066 lapse (+ below_min) exception. teams/team_members/team_exceptions are system-only writes; this is the scheduled actor.',

    'App\Services\Teams\TeamResolutionService::assign' => 'Below-min resolution — ASSIGN (S05-4, OD-37): an admin places an unplaced student into a below-min team; one seat is claimed under FOR UPDATE, the enrolment moves in_pool → teamed → confirmed, and a guardian payment_obligation is written. Admin authority + lobby eligibility checked before the elevation.',

    'App\Services\Teams\TeamResolutionService::extendGrace' => 'Below-min resolution — GRACE-ONCE (S05-4, OD-37): an admin extends a suspended member\'s payment grace exactly once and un-suspends them. A second extension is refused (grace is not a loop, OD-31/37). team_members is a system-only write; authority checked before the elevation.',

    'App\Services\Teams\TeamResolutionService::waive' => 'Below-min resolution — WAIVE (S05-4, OD-40): an admin grants an under-strength waiver, stored as a reason field on the team so the nightly size check reads "meets rules OR waiver". teams is a system-only write; authority checked before the elevation.',

    'App\Services\Teams\TeamResolutionService::dissolve' => 'Below-min resolution — DISSOLVE (S05-4, OD-38): an admin disbands the team; each live member\'s enrolment moves confirmed → in_pool (re-pooled in-lobby), PAID orders are kept untouched (no re-charge, no refund) and unpaid orders are cancelled, then the team is disbanded. System-only writes; authority checked before the elevation.',

    'App\Services\Teams\TeamResolutionService::recordSchoolLeave' => 'School-leave record (S05-4, OD-62): a student leaves school mid-programme. The team STANDS — no membership or team state change — and an academy school_leave exception is raised. team_exceptions is a system-only write; authority checked before the elevation.',

    'App\Services\Teams\TeamTeacherLinkService::link' => 'Team-teacher link (S05-5, OD-61): the lobby school admin or an academy admin links a teacher to a TEAM (not to students). team_teacher_links is a system-only write; the admin authority was established before the elevation.',

    'App\Services\Teams\RoleRotationService::assignRole' => 'Role rotation recording (S05-5, OD-15): staff record a role assignment; the prior active tenure for this (team, role) is completed and a fresh active one opened — the ledger handover. tenures is a system-only write; the recorder\'s authority was established before the elevation.',

    'App\Services\Sessions\SessionService::create' => 'Session create (S06-2): the organiser creates a Draft session bound to a programme (optionally a team, D4). programme_sessions is a system-only write; the academy-operator authority was established before the elevation.',

    'App\Services\Sessions\SessionService::transition' => 'Session lifecycle transition (S06-2, 2.3): the organiser advances a session through its state machine (draft→published→full→in_progress→completed/cancelled). programme_sessions.status is a system-only write; authority established before the elevation.',

    'App\Services\Sessions\SessionService::reschedule' => 'Session reschedule (S06-2, 2.3/2.24): the organiser moves a session; the pre-change snapshot is written to session_versions, bookings are retained, booking re-opens if capacity grew, and clashing students are computed for re-notification. programme_sessions/session_versions are system-only writes; authority established before the elevation.',

    'App\Services\Sessions\SessionService::clashPreview' => 'Session clash preview (S06-2, 2.24): read-only count of booked students who would clash at a proposed new time — spans bookings across sessions the organiser cannot otherwise see. No write.',

    'App\Services\Sessions\MentorService::setStatus' => 'Mentor lifecycle (S06-2, 2.6): the academy moves a mentor active/inactive/departed; departing is refused while future non-terminal sessions remain (reassign or reschedule first). mentors is a system-only write; authority established before the elevation.',

    'App\Services\Sessions\BookingService::book' => 'Session booking (S06-3): a student books a session in a programme they are live in; capacity is claimed under FOR UPDATE on the session row (BI-3), over-capacity waitlists. The booking student\'s own enrolment is the only one touched.',

    'App\Services\Sessions\BookingService::cancel' => 'Session booking cancel (S06-3): a student cancels their booking under FOR UPDATE on the session row; a freed booked slot auto-promotes the earliest waitlisted booking (or the full session re-opens). Only this student\'s booking and at most one promoted booking are touched.',

    'App\Services\Sessions\SessionAdvancementService::run' => 'Session advancement job (S06-7, 2.3): the SYSTEM advances sessions past their time — published/full → in_progress at start, in_progress → completed at end. programme_sessions.status is a system-only write; this is the scheduled actor. Only sessions whose time has passed are touched.',

    'App\Services\Sessions\BookingService::cascadeWithdrawal' => 'Withdrawal booking cascade (S06-6, 2.21): a Withdrawn enrolment\'s FUTURE session bookings are cancelled and waitlist slots released — each under FOR UPDATE on its session row, auto-promoting behind a freed booked seat. Only the withdrawn enrolment\'s bookings (and at most one promotion each) are touched.',

    'App\Services\Sessions\AttendanceService::mark' => 'Attendance capture (S06-3): the mentor or academy records a booked student attended/no_show on an in-progress/completed session, stamping the recorder\'s identity. The recorder authority (session mentor, or academy operations) is resolved WITHIN the elevation via explicit actor-id filters — a recorder may not read the session through RLS, but the authority is a policy call, not a visibility one. session_bookings is a system-only write.',

    'App\Services\Members\EventService::create' => 'Event create (S06-5, OD-22): the academy creates a Draft network event. events is a system-only write; the academy-operator authority was established before the elevation.',

    'App\Services\Members\EventService::transition' => 'Event lifecycle transition (S06-5, OD-22): the academy publishes or cancels a network event. events.status is a system-only write; authority established before the elevation.',

    'App\Services\Members\MemberSurfaceService::rsvp' => 'Member RSVP (S06-5, OD-22): a Member records their own RSVP to a published event. event_rsvps is a system-only write; only the acting Member\'s own rsvp row is touched.',

    'App\Services\Members\MemberSurfaceService::upsertProfile' => 'Member profile upsert (S06-5, OD-22): a Member edits their own directory profile. member_profiles is a system-only write; only the acting Member\'s own profile is touched.',

    'App\Services\Assessments\AssessmentService::create' => 'Assessment create (S06-4b, 2.5): the academy creates a Draft assessment for a programme (optionally a team). assessments is a system-only write; the academy-operator authority was established before the elevation.',

    'App\Services\Assessments\AssessmentService::transition' => 'Assessment lifecycle transition (S06-4b, 2.5): the academy advances an assessment (draft→published→open→closed→graded→released). RELEASED lifts the result embargo. assessments.status is a system-only write; authority established before the elevation.',

    'App\Services\Assessments\AssessmentService::grade' => 'Assessment grading (S06-4b, 2.5): the academy records a student\'s result on a closed/graded assessment. assessment_results is a system-only write; the embargo (results hidden until Released) is enforced separately at READ. Grader authority established before the elevation.',

    'App\Services\Enrolments\EnrolmentActivationService::run' => 'Enrolment activation job (S06-1, R3): the SYSTEM activates confirmed enrolments whose programme has started (basics.starts_on ≤ now) — payment-decoupled, keyed purely on "confirmed AND started". enrolments state writes are system-only (S04A); this is the scheduled actor. Transitions confirmed → active only.',

    'App\Services\Teams\TrackerService::approveGate' => 'Stage-gate approval (S05-5, OD-61): a team-linked teacher, the lobby school admin, or academy ops records a gate pass. The approver\'s authority (team-linked, not student-linked) is resolved WITHIN the elevation using explicit actor-id filters — a gate approver may not be able to read the team through RLS, but the OD-61 decision is a policy call, not a visibility one. stage_gates is a system-only write.',
];

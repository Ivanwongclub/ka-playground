<?php

use App\Http\Controllers\AccessIdentityReportController;
use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\ConsentRequestController;
use App\Http\Controllers\ConsentTemplateController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProgrammeConfigController;
use App\Http\Controllers\ProgrammeController;
use App\Http\Controllers\SchoolAdminController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\WizardController;
use Illuminate\Support\Facades\Route;

// Permission-guarded resource surfaces. The guarded routes exist from S01 STEP 1
// so denial is enforceable and testable (OD-1 Member control); their real
// payloads arrive with their sprints (S02–S04B) — until then they answer 501.
Route::middleware('auth:sanctum')->group(function (): void {
    $notImplemented = fn () => response()->json(
        ['message' => 'Not implemented until its sprint'],
        501,
    );

    // S04C step 2 — registration review (routed reviewer decides; RLS scopes which
    // requests are theirs — school admin = own routed, academy ops = direct + all).
    Route::post('/admin/registration-requests/{id}/approve', [\App\Http\Controllers\RegistrationReviewController::class, 'approve']);
    Route::post('/admin/registration-requests/{id}/decline', [\App\Http\Controllers\RegistrationReviewController::class, 'decline']);
    // S04C step 3 — the link-approval decision (FLAG #2): activates a RELATIONSHIP,
    // separate from approving a person. Reviewer roles only; RLS scopes the rows.
    Route::post('/admin/guardian-links/{id}/approve', [\App\Http\Controllers\GuardianLinkReviewController::class, 'approve']);
    Route::post('/admin/guardian-links/{id}/reject', [\App\Http\Controllers\GuardianLinkReviewController::class, 'reject']);
    // S04C step 4 — the ONE queue (read; RLS-scoped per approver).
    Route::get('/admin/onboarding-queue', [\App\Http\Controllers\OnboardingQueueController::class, 'index']);

    Route::get('/students', $notImplemented)->middleware('permission:student_records.view');
    Route::get('/consents', $notImplemented)->middleware('permission:consent.view');
    Route::post('/consents/sign', $notImplemented)->middleware('permission:consent.sign');
    Route::get('/enrolments', [\App\Http\Controllers\EnrolmentController::class, 'index'])
        ->middleware('permission:enrolment.view'); // S04A: RLS-shaped
    Route::post('/my/enrolments', [\App\Http\Controllers\EnrolmentController::class, 'store'])
        ->middleware('role:guardian'); // S04A: creation records the acting guardian (2.22)
    // S04A step 4 — withdrawal workflow (BI-7 state only; OD-26 fixed approver)
    Route::post('/my/enrolments/{enrolmentId}/withdrawal', [\App\Http\Controllers\WithdrawalController::class, 'store'])
        ->middleware('role:guardian');
    Route::post('/withdrawal-requests/{id}/cancel', [\App\Http\Controllers\WithdrawalController::class, 'cancel'])
        ->middleware('role:guardian');
    Route::post('/withdrawal-requests/{id}/endorse', [\App\Http\Controllers\WithdrawalController::class, 'endorse'])
        ->middleware('role:school_admin');
    Route::post('/admin/withdrawal-requests/{id}/decide', [\App\Http\Controllers\WithdrawalController::class, 'decide'])
        ->middleware('permission:operations.manage');
    Route::get('/withdrawal-requests', [\App\Http\Controllers\WithdrawalController::class, 'index']); // RLS-shaped
    Route::get('/payments', [\App\Http\Controllers\ManualPaymentController::class, 'index'])
        ->middleware('permission:finance.view'); // S04B step 4: real surface; RLS-shaped inside the S01 gate
    // Manual recording under BI-9 (OD-47 scope): record and decide are SEPARATE
    // permissions AND separate people — the DB policy refuses recorder=confirmer
    Route::post('/admin/payments', [\App\Http\Controllers\ManualPaymentController::class, 'record'])
        ->middleware('permission:finance.record');
    Route::post('/admin/payments/{id}/confirm', [\App\Http\Controllers\ManualPaymentController::class, 'confirm'])
        ->middleware('permission:finance.confirm');
    Route::post('/admin/payments/{id}/reject', [\App\Http\Controllers\ManualPaymentController::class, 'reject'])
        ->middleware('permission:finance.confirm');
    // S04B step 5 — refund payout (2.17) under BI-9: approve ≠ confirm, DB-enforced
    Route::get('/refunds', [\App\Http\Controllers\RefundController::class, 'index'])->middleware('permission:finance.view');
    Route::get('/credit-notes', [\App\Http\Controllers\RefundController::class, 'creditNotes'])->middleware('permission:finance.view');
    Route::post('/admin/refunds/{id}/approve', [\App\Http\Controllers\RefundController::class, 'approve'])->middleware('permission:finance.record');
    Route::post('/admin/refunds/{id}/confirm', [\App\Http\Controllers\RefundController::class, 'confirm'])->middleware('permission:finance.confirm');
    Route::post('/admin/refunds/{id}/reject', [\App\Http\Controllers\RefundController::class, 'reject'])->middleware('permission:finance.confirm');
    // S04B step 1 — RLS-shaped money reads (OD-67: guardians+student read;
    // school admins get ZERO family orders; finance/audit see all)
    Route::get('/orders', fn () => response()->json(['data' => \Illuminate\Support\Facades\DB::table('orders')->orderBy('created_at')->get(['id', 'enrolment_id', 'programme_id', 'student_id', 'payer_party', 'status', 'total_amount_minor', 'currency', 'payment_due_at'])]));
    Route::get('/orders/{id}/lines', fn (string $id) => response()->json(['data' => \Illuminate\Support\Facades\DB::table('order_lines')->where('order_id', $id)->get(['id', 'name_en', 'name_tc', 'name_sc', 'amount_minor', 'currency'])]));
    Route::get('/receipts', fn () => response()->json(['data' => \Illuminate\Support\Facades\DB::table('receipts')->orderBy('receipt_number')->get(['id', 'order_id', 'sequence_key', 'receipt_number', 'amount_minor', 'currency', 'issued_at'])]));
    // S04B step 3 — payment links (OD-44). Mint = the guardian's own audited
    // act on their own order; the list NEVER exposes token_hash (ruling 6)
    Route::post('/my/orders/{id}/payment-link', [\App\Http\Controllers\PaymentLinkController::class, 'mint'])
        ->middleware('role:guardian');
    Route::get('/my/payment-links', [\App\Http\Controllers\PaymentLinkController::class, 'index']);

    Route::post('/admin/capabilities/grant', [CapabilityController::class, 'grant']);
    Route::post('/admin/capabilities/revoke', [CapabilityController::class, 'revoke']);

    Route::post('/admin/invitations', [OnboardingController::class, 'issue'])
        ->middleware('permission:operations.manage');
    // OD-27: guardian-creates-student (POST /my/students) is RETIRED — self-
    // registration + approval (S04C) creates students now. The endpoint, service
    // and its elevation are gone in the same step self-registration went live.

    // Linking flows (B4) + continuity (2.2)
    Route::post('/my/pairing-codes', [LinkController::class, 'generateCode'])->middleware('role:student');
    Route::post('/pairing-codes/redeem', [LinkController::class, 'redeemCode'])
        ->middleware(['role:guardian', 'throttle:pairing']);
    Route::post('/my/guardian-requests/{id}/confirm', [LinkController::class, 'confirm'])->middleware('role:student');
    Route::post('/my/link-requests', [LinkController::class, 'requestByEmail'])->middleware('role:guardian');
    Route::post('/school/guardian-links', [LinkController::class, 'schoolVouch'])->middleware('role:school_admin');
    Route::middleware('role:school_admin')->group(function (): void {
        Route::get('/school/students', [SchoolAdminController::class, 'students']);
        Route::get('/school/students/{id}', [SchoolAdminController::class, 'student']);
        Route::get('/school/teachers', [SchoolAdminController::class, 'teachers']);
        Route::post('/school/teachers/invite', [SchoolAdminController::class, 'inviteTeacher']);
        // S04D step 4 — bulk-create students on the school's roll (rows, not CSV).
        Route::post('/school/bulk-students', [SchoolAdminController::class, 'bulkStudents']);
    });
    // Ops contrast (the platform owner CAN cross schools): all school links
    Route::get('/admin/students', fn () => response()->json([
        'data' => \Illuminate\Support\Facades\DB::table('school_links')
            ->join('users', 'users.id', '=', 'school_links.student_id')
            ->where('school_links.status', 'active')
            ->get(['users.id', 'users.name', 'school_links.school_id']),
    ]))->middleware('permission:operations.manage');
    Route::post('/guardian-links/{id}/revoke', [LinkController::class, 'revoke']);

    // S02A step 2 — configuration surfaces (OD-17: configuration capability)
    Route::middleware('permission:configuration.manage')->group(function (): void {
        Route::get('/admin/schools', [SchoolController::class, 'index']);
        Route::post('/admin/schools', [SchoolController::class, 'store']);
        Route::put('/admin/schools/{id}', [SchoolController::class, 'update']);
        Route::get('/admin/programmes', [ProgrammeController::class, 'index']);
        Route::post('/admin/programmes', [ProgrammeController::class, 'store']);
        Route::put('/admin/programmes/{id}', [ProgrammeController::class, 'update']);
        Route::post('/admin/programmes/{id}/versions', [ProgrammeController::class, 'snapshot']);

        // S02B step 1 — hub-and-spoke wizard (Part D)
        Route::get('/admin/programmes/{id}/wizard', [WizardController::class, 'state']);
        Route::put('/admin/programmes/{id}/wizard/{key}', [WizardController::class, 'saveSection']);
        Route::post('/admin/programmes/{id}/pre-flight', [WizardController::class, 'preFlight']);
        Route::post('/admin/programmes/{id}/publish', [WizardController::class, 'publish']);
        Route::post('/admin/programmes/{id}/save-as-template', [WizardController::class, 'saveAsTemplate']);
        Route::post('/admin/programmes/{id}/create-from-template', [WizardController::class, 'createFromTemplate']);

        // S02B step 2 — config CRUD
        Route::post('/admin/programmes/{id}/team-categories', [ProgrammeConfigController::class, 'storeCategory']);
        Route::post('/admin/programmes/{id}/team-categories/{categoryId}/retire', [ProgrammeConfigController::class, 'retireCategory']);
        Route::post('/admin/programmes/{id}/fee-items', [ProgrammeConfigController::class, 'storeFeeItem']);
        Route::put('/admin/programmes/{id}/certification-rules', [ProgrammeConfigController::class, 'saveCertificationRules']);
        Route::put('/admin/programmes/{id}/withdrawal-policy', [ProgrammeConfigController::class, 'saveWithdrawalPolicy']);

        // S03 step 1 — consent templates + language-scoped versions
        Route::get('/admin/consent-templates', [ConsentTemplateController::class, 'index']);
        Route::post('/admin/consent-templates', [ConsentTemplateController::class, 'store']);
        Route::post('/admin/consent-templates/placeholder', [ConsentTemplateController::class, 'seedPlaceholder']);
        Route::post('/admin/consent-templates/{id}/versions', [ConsentTemplateController::class, 'draftVersion']);
        Route::post('/admin/consent-templates/{id}/versions/{versionId}/publish', [ConsentTemplateController::class, 'publishVersion']);
    });

    // S03 step 2 — signing flow (FR036). Manual issuance was RETIRED at S04A
    // STEP 1 (S03 §5 item 4): consent_requests INSERT is system-only; requests
    // are issued by system jobs (enrolment, re-issue after void). Signing acts
    // remain the addressed guardian's alone (consent.sign held by NO capability).
    Route::post('/admin/consent-requests/{id}/void', [ConsentRequestController::class, 'void'])
        ->middleware('permission:operations.manage');
    Route::get('/my/students/{studentId}/consent-status', [ConsentRequestController::class, 'derivedStatus'])
        ->middleware('role:guardian');
    Route::get('/consent-requests', [ConsentRequestController::class, 'index']); // RLS-shaped
    Route::get('/consent-signatures', [ConsentRequestController::class, 'signatures']); // RLS-shaped
    Route::get('/consent-documents', [ConsentRequestController::class, 'documents']); // RLS-shaped
    Route::get('/consent-documents/{id}/download', [ConsentRequestController::class, 'download']); // RLS-shaped + BI-10
    Route::get('/consent-requests/{id}/document', [ConsentRequestController::class, 'document'])
        ->middleware('role:guardian');
    Route::post('/consent-requests/{id}/scrolled', [ConsentRequestController::class, 'scrolled'])
        ->middleware('role:guardian');
    Route::post('/consent-requests/{id}/sign', [ConsentRequestController::class, 'sign'])
        ->middleware('permission:consent.sign');
    Route::post('/consent-requests/{id}/decline', [ConsentRequestController::class, 'decline'])
        ->middleware('role:guardian');

    // S05 step 1 — team formation in lobbies (TEAM-CATEGORIES §4-§8)
    Route::get('/programmes/{programmeId}/lobbies', [\App\Http\Controllers\FormationController::class, 'lobbies'])->middleware('role:student');
    Route::post('/my/teams', [\App\Http\Controllers\FormationController::class, 'create'])->middleware('role:student');
    Route::post('/teams/{id}/join', [\App\Http\Controllers\FormationController::class, 'join'])->middleware('role:student');
    Route::get('/teams', [\App\Http\Controllers\FormationController::class, 'index']); // RLS-shaped
    // S05 step 2 — 成團: submit (student) then approve (school admin of lobby / academy ops)
    Route::post('/teams/{id}/submit', [\App\Http\Controllers\TeamConfirmationController::class, 'submit'])->middleware('role:student');
    Route::post('/teams/{id}/confirm', [\App\Http\Controllers\TeamConfirmationController::class, 'confirm']); // authority checked in-service (OD-39)
    // S05 step 3 — deadline matching screen (OD-35): screen read is RLS-shaped; action authority in-service (academy operations)
    Route::get('/admin/programmes/{programmeId}/matching', [\App\Http\Controllers\MatchingController::class, 'screen']);
    Route::post('/admin/matching/match', [\App\Http\Controllers\MatchingController::class, 'match']);
    Route::post('/admin/matching/roll', [\App\Http\Controllers\MatchingController::class, 'roll']);
    Route::post('/admin/matching/release', [\App\Http\Controllers\MatchingController::class, 'release']);
    // S05 step 4 — the four terminal actions on a below-min exception (OD-37); authority in-service (academy operations)
    Route::post('/admin/teams/{id}/assign', [\App\Http\Controllers\TeamResolutionController::class, 'assign']);
    Route::post('/admin/teams/{id}/extend-grace', [\App\Http\Controllers\TeamResolutionController::class, 'extendGrace']);
    Route::post('/admin/teams/{id}/waive', [\App\Http\Controllers\TeamResolutionController::class, 'waive']);
    Route::post('/admin/teams/{id}/dissolve', [\App\Http\Controllers\TeamResolutionController::class, 'dissolve']);
    Route::post('/admin/team-members/{id}/school-leave', [\App\Http\Controllers\TeamResolutionController::class, 'schoolLeave']); // OD-62; {id} = enrolment id
    // S05 step 5 — roles & tracker (OD-15/61); authority in-service
    Route::post('/admin/teams/{id}/teacher-link', [\App\Http\Controllers\RolesTrackerController::class, 'linkTeacher']);
    Route::post('/teams/{id}/roles', [\App\Http\Controllers\RolesTrackerController::class, 'assignRole']);
    Route::post('/teams/{id}/gates/{stage}/approve', [\App\Http\Controllers\RolesTrackerController::class, 'approveGate']);
    // S05-6 audit element — the Team & Capacity Report (RLS-shaped)
    Route::get('/admin/programmes/{id}/team-capacity-report', [\App\Http\Controllers\TeamCapacityReportController::class, 'show']);
    // S06-2 — session lifecycle (2.3) + reschedule/clash (2.24) + mentor lifecycle (2.6); authority in-service
    Route::post('/admin/programmes/{programmeId}/sessions', [\App\Http\Controllers\SessionController::class, 'store']);
    Route::post('/admin/sessions/{id}/transition', [\App\Http\Controllers\SessionController::class, 'transition']);
    Route::post('/admin/sessions/{id}/reschedule', [\App\Http\Controllers\SessionController::class, 'reschedule']);
    Route::post('/admin/sessions/{id}/clash-preview', [\App\Http\Controllers\SessionController::class, 'clashPreview']);
    Route::get('/admin/programmes/{id}/attendance-report', [\App\Http\Controllers\SessionAttendanceReportController::class, 'show']); // S06-7 audit element
    Route::post('/admin/mentors/{userId}/status', [\App\Http\Controllers\MentorController::class, 'setStatus']);
    // S06-3 — booking workflow (student self-service) + attendance capture (mentor/academy)
    Route::post('/my/sessions/{id}/book', [\App\Http\Controllers\BookingController::class, 'book'])->middleware('role:student');
    Route::post('/my/sessions/{id}/cancel', [\App\Http\Controllers\BookingController::class, 'cancel'])->middleware('role:student');
    Route::post('/admin/sessions/{id}/attendance', [\App\Http\Controllers\AttendanceController::class, 'mark']); // authority in-service (mentor/ops)
    // S06-4b — assessment lifecycle (2.5); the result read is RLS-embargoed (hidden until Released)
    Route::post('/admin/programmes/{programmeId}/assessments', [\App\Http\Controllers\AssessmentController::class, 'store']);
    Route::post('/admin/assessments/{id}/transition', [\App\Http\Controllers\AssessmentController::class, 'transition']);
    Route::post('/admin/assessments/{id}/grade', [\App\Http\Controllers\AssessmentController::class, 'grade']);
    Route::get('/admin/assessments/{id}/results', [\App\Http\Controllers\AssessmentController::class, 'results']);
    Route::get('/assessments/{id}/results/{studentId}', [\App\Http\Controllers\AssessmentController::class, 'result']); // RLS-embargoed
    // S06-5 — Member surfaces (OD-22/FR058): events (network-wide) + RSVP (per-member) + directory
    Route::post('/admin/events', [\App\Http\Controllers\EventController::class, 'store']);
    Route::post('/admin/events/{id}/transition', [\App\Http\Controllers\EventController::class, 'transition']);
    Route::get('/events', [\App\Http\Controllers\EventController::class, 'index']); // RLS-shaped (Members see published)
    Route::post('/events/{id}/rsvp', [\App\Http\Controllers\MemberController::class, 'rsvp'])->middleware('role:member');
    Route::get('/my/rsvps', [\App\Http\Controllers\MemberController::class, 'myRsvps'])->middleware('role:member');
    Route::get('/directory', [\App\Http\Controllers\MemberController::class, 'directory']); // RLS-shaped (Members only)
    Route::put('/my/profile', [\App\Http\Controllers\MemberController::class, 'profile'])->middleware('role:member');

    // Reads shaped by RLS alone (S05 formation will consume these)
    Route::get('/programmes/{id}/team-categories', [ProgrammeConfigController::class, 'categories']);
    Route::get('/programmes/{id}/withdrawal-policy', [ProgrammeConfigController::class, 'withdrawalPolicy']);
    Route::get('/consent-templates/{id}/versions', [ConsentTemplateController::class, 'versions']);
    Route::get('/admin/programmes/{id}/fee-items', [ProgrammeConfigController::class, 'feeItems'])
        ->middleware('permission:finance.view');
});

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/admin/users/{id}/unlock', [AuthController::class, 'unlock'])
        ->middleware('permission:operations.manage');
});

// S04B step 3 — THE ONLY unauthenticated money surface (OD-44; single_reader
// assertion enforces exactly these two): multi-view resolve + single-act confirm
Route::get('/pay/{token}', [\App\Http\Controllers\PaymentLinkController::class, 'resolve'])
    ->middleware('throttle:payment-link');
Route::post('/pay/{token}/confirm', [\App\Http\Controllers\PaymentLinkController::class, 'confirm'])
    ->middleware('throttle:payment-link');

// S04C step 1 — the platform's FIRST anonymous WRITE (OD-23). The picker read
// is a filtered listed-schools read; submit is the confined public-context write.
// Constant-shape 202, no status endpoint. throttle:registration on both.
Route::get('/register/schools', [\App\Http\Controllers\RegistrationController::class, 'schools'])
    ->middleware('throttle:registration');
Route::post('/register', [\App\Http\Controllers\RegistrationController::class, 'submit'])
    ->middleware('throttle:registration');
// S04C step 2 — activation (verify + set password in one act, OD-29 model B).
// Guest: the single-use token is the access control. throttle:auth (credential surface).
Route::post('/register/activate', [\App\Http\Controllers\RegistrationController::class, 'activate'])
    ->middleware('throttle:auth');

// Guest onboarding surface (2.11) — throttling arrives with step 4
Route::post('/onboarding/accept', [OnboardingController::class, 'accept'])->middleware('throttle:auth');
Route::get('/onboarding/verify-email/{id}/{hash}', [OnboardingController::class, 'verifyEmail'])
    ->middleware('signed')->name('verification.verify');

// S00 surface, secured in S01 STEP 6: Sanctum + the audit_read capability (OD-17)
Route::get('/audit-events', [AuditEventController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:audit.read']);
Route::get('/reports/access-identity', [AccessIdentityReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:audit.read']);

// S03 audit element: Consent Evidence Report + per-signature bundle export
Route::get('/reports/enrolment-pool', [\App\Http\Controllers\EnrolmentPoolReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:audit.read']); // S04A audit element
Route::get('/reports/financial-integrity', [\App\Http\Controllers\FinancialIntegrityReportController::class, 'index'])
    ->middleware('auth:sanctum'); // S04B audit element — finance/audit gated in-controller, academy-scoped
Route::get('/reports/consent-evidence', [\App\Http\Controllers\ConsentEvidenceReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:audit.read']);
Route::get('/reports/consent-evidence/{signatureId}/bundle', [\App\Http\Controllers\ConsentEvidenceReportController::class, 'bundle'])
    ->middleware(['auth:sanctum', 'permission:audit.read']);

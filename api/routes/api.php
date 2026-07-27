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
    Route::get('/payments', $notImplemented)->middleware('permission:finance.view');
    // S04B step 1 — RLS-shaped money reads (OD-67: guardians+student read;
    // school admins get ZERO family orders; finance/audit see all)
    Route::get('/orders', fn () => response()->json(['data' => \Illuminate\Support\Facades\DB::table('orders')->orderBy('created_at')->get(['id', 'enrolment_id', 'programme_id', 'student_id', 'payer_party', 'status', 'total_amount_minor', 'currency', 'payment_due_at'])]));
    Route::get('/orders/{id}/lines', fn (string $id) => response()->json(['data' => \Illuminate\Support\Facades\DB::table('order_lines')->where('order_id', $id)->get(['id', 'name_en', 'name_tc', 'name_sc', 'amount_minor', 'currency'])]));
    Route::get('/receipts', fn () => response()->json(['data' => \Illuminate\Support\Facades\DB::table('receipts')->orderBy('receipt_number')->get(['id', 'order_id', 'sequence_key', 'receipt_number', 'amount_minor', 'currency', 'issued_at'])]));

    Route::post('/admin/capabilities/grant', [CapabilityController::class, 'grant']);
    Route::post('/admin/capabilities/revoke', [CapabilityController::class, 'revoke']);

    Route::post('/admin/invitations', [OnboardingController::class, 'issue'])
        ->middleware('permission:operations.manage');
    Route::post('/my/students', [OnboardingController::class, 'createStudent'])
        ->middleware('role:guardian');

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
Route::get('/reports/consent-evidence', [\App\Http\Controllers\ConsentEvidenceReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:audit.read']);
Route::get('/reports/consent-evidence/{signatureId}/bundle', [\App\Http\Controllers\ConsentEvidenceReportController::class, 'bundle'])
    ->middleware(['auth:sanctum', 'permission:audit.read']);

<?php

use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\OnboardingController;
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
    Route::get('/enrolments', $notImplemented)->middleware('permission:enrolment.view');
    Route::get('/payments', $notImplemented)->middleware('permission:finance.view');

    Route::post('/admin/capabilities/grant', [CapabilityController::class, 'grant']);
    Route::post('/admin/capabilities/revoke', [CapabilityController::class, 'revoke']);

    Route::post('/admin/invitations', [OnboardingController::class, 'issue'])
        ->middleware('permission:operations.manage');
    Route::post('/my/students', [OnboardingController::class, 'createStudent'])
        ->middleware('role:guardian');
});

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/admin/users/{id}/unlock', [AuthController::class, 'unlock'])
        ->middleware('permission:operations.manage');
});

// Guest onboarding surface (2.11) — throttling arrives with step 4
Route::post('/onboarding/accept', [OnboardingController::class, 'accept']);
Route::get('/onboarding/verify-email/{id}/{hash}', [OnboardingController::class, 'verifyEmail'])
    ->middleware('signed')->name('verification.verify');

// S00 surface — goes behind auth:sanctum + audit_read in S01 STEP 6 (card step 6)
Route::get('/audit-events', [AuditEventController::class, 'index']);

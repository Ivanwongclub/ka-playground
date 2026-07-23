<?php

use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\CapabilityController;
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
});

// S00 surface — goes behind auth:sanctum + audit_read in S01 STEP 6 (card step 6)
Route::get('/audit-events', [AuditEventController::class, 'index']);

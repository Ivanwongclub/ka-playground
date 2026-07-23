<?php

use App\Http\Controllers\AuditEventController;
use Illuminate\Support\Facades\Route;

// Auth middleware (Sanctum + capability checks, OD-17) lands in S01.
// This stack is local-only and undeployed until then.
Route::get('/audit-events', [AuditEventController::class, 'index']);

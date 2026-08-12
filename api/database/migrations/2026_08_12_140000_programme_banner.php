<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAP-MKT-1 — the programme storefront banner. A nullable reference to an `uploads` row that has passed the
 * BI-10 ClamAV scan. Stored on `programmes` (which has no RLS — the catalogue already reads it, and the
 * banner is public marketing, no PII). The image is set via the wizard marketing section's upload control;
 * it is OPTIONAL — `WizardService::marketingLanguageGaps` (the storefront completeness gate) is UNCHANGED
 * and never requires the banner. A not-yet-clean or absent banner → the storefront renders the brand_color.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programmes', function (Blueprint $table) {
            $table->uuid('banner_upload_id')->nullable(); // KAP-MKT-1: scan-clean storefront banner (optional)
        });
    }

    public function down(): void
    {
        Schema::table('programmes', function (Blueprint $table) {
            $table->dropColumn('banner_upload_id');
        });
    }
};

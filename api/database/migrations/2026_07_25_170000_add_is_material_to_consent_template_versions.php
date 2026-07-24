<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S03 STEP 4 — FR037: materiality is declared at publish. A material version
// supersedes signatures IN ITS LANGUAGE (OD-20a) and triggers re-consent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_template_versions', function (Blueprint $table) {
            $table->boolean('is_material')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('consent_template_versions', function (Blueprint $table) {
            $table->dropColumn('is_material');
        });
    }
};

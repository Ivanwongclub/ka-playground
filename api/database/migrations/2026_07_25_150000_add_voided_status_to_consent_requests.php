<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// S03 STEP 2 amendment (Leo item 4): an issued request whose FROZEN merge data
// no longer matches source is VOIDED and re-issued — never silently re-rendered,
// which would break the rendered-hash binding.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE consent_requests DROP CONSTRAINT cr_status_check');
        DB::statement("ALTER TABLE consent_requests ADD CONSTRAINT cr_status_check CHECK (status IN ('draft','sent','viewed','signed','declined','expired','superseded','voided'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE consent_requests DROP CONSTRAINT cr_status_check');
        DB::statement("ALTER TABLE consent_requests ADD CONSTRAINT cr_status_check CHECK (status IN ('draft','sent','viewed','signed','declined','expired','superseded'))");
    }
};

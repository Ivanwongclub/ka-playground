<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S06 STEP 3 — attendance capture on the booking. Attendance is recorded ON the
// booking (status attended|no_show, already in the CHECK) with the RECORDER's
// identity and time. No new tables: "attendance records == attended bookings"
// (the gate assertion) holds by construction. The booking workflow (book /
// waitlist / auto-promote) uses the columns already present (status + created_at
// for waitlist order); this migration only adds the recorder fields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_bookings', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->constrained('users'); // who marked attendance
            $table->timestampTz('recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('session_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn('recorded_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S06 STEP 5 — Member surfaces (OD-22 / FR058): the first-generation Kings
// Network Members' event list, RSVP, and read-only directory. The Member role
// has events/RSVP/directory and NOTHING else — the absence of every enrolment/
// consent/finance/student surface is the control.
//
// R-Member watch: EVENTS are network-wide (every Member reads every published
// event); EVENT_RSVPS are PER-MEMBER (a Member reads only their OWN rsvp) — the
// rsvp policy must NOT inherit the events breadth. The Member "marker" is
// actor_role='member' (ScopeContext sets it; a Member has no link-derived scope).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title_en');
            $table->string('title_tc');
            $table->string('title_sc');
            $table->timestampTz('starts_at');
            $table->string('location')->nullable();
            $table->string('status')->default('draft'); // draft|published|cancelled
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->index('status');
        });
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft','published','cancelled'))");

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->foreignId('member_id')->constrained('users');
            $table->string('status')->default('going'); // going|not_going|maybe
            $table->timestampsTz();
            $table->unique(['event_id', 'member_id']);
        });
        DB::statement("ALTER TABLE event_rsvps ADD CONSTRAINT rsvp_status_check CHECK (status IN ('going','not_going','maybe'))");

        Schema::create('member_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('display_name');
            $table->string('headline')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestampsTz();
        });

        // ---- RLS ----
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $member = "{$role} = 'member'"; // the Member marker — network-scoped, not link-derived

        foreach (['events', 'event_rsvps', 'member_profiles'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_update ON {$t} FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_delete ON {$t} FOR DELETE USING ({$system})");
        }

        // events: NETWORK-WIDE for Members (every published event), plus academy
        DB::unprepared("CREATE POLICY events_read ON events FOR SELECT USING ({$system} OR {$opsAudit} OR (status = 'published' AND {$member}))");
        // event_rsvps: PER-MEMBER — a Member sees ONLY their own rsvp (does NOT inherit the events breadth)
        DB::unprepared("CREATE POLICY event_rsvps_read ON event_rsvps FOR SELECT USING ({$system} OR {$opsAudit} OR member_id::text = {$actor})");
        // member_profiles: the directory — Members see all VISIBLE profiles (+ their own); academy sees all
        DB::unprepared("CREATE POLICY member_profiles_read ON member_profiles FOR SELECT USING (
            {$system} OR {$opsAudit} OR ({$member} AND (visible OR user_id::text = {$actor})))");
    }

    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('events');
    }
};

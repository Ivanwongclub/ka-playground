<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S07 STEP 3 (FR057 · OD-4 · Spec §L6/Pitch) — the team's project type +
 * fundraising target. Reframes the Pitch stage (spec:1296) to cover commercial
 * SPONSORSHIP and philanthropic CHARITY. Record-only, WHOLLY SEPARATE from the
 * Order module (§A3/GR006).
 *
 *  - team_fundraising: ONE per team — the project's `project_type`
 *    (sponsorship | charity) + its funding target. This is the OD-4 anchor: a
 *    `charity` project may record income and legitimate expenses but NEVER a
 *    distribution to a team member (an expense with a beneficiary_member_id).
 *  - sponsorship funds are recorded as INCOME team_transactions (STEP 2), the
 *    sponsorship agreement riding the transaction's evidence (BI-10) — no
 *    duplicate ledger; a sponsorship record IS an income transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_fundraising', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->unique();                       // one per team
            $table->string('project_type');                         // sponsorship | charity
            $table->bigInteger('funding_target_minor')->default(0);
            $table->char('currency', 3)->default('HKD');
            $table->foreignId('declared_by')->constrained('users');
            $table->timestampsTz();
            $table->foreign('team_id')->references('id')->on('teams');
        });
        DB::statement("ALTER TABLE team_fundraising ADD CONSTRAINT tf_type_check CHECK (project_type IN ('sponsorship','charity'))");
        DB::statement("ALTER TABLE team_fundraising ADD CONSTRAINT tf_currency_check CHECK (currency = 'HKD')");
        DB::statement('ALTER TABLE team_fundraising ADD CONSTRAINT tf_target_nonneg CHECK (funding_target_minor >= 0)');

        // ── RLS: team-scoped read (members · linked teacher · lobby admin · ops/audit); system write ──
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'finance' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $memberOf = "EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = team_fundraising.team_id AND tm.status <> 'removed' AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $teacher = "({$role} = 'teacher' AND EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = team_fundraising.team_id AND ttl.teacher_id::text = {$actor} AND ttl.status = 'active'))";
        $lobbyAdmin = "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM teams t JOIN team_categories c ON c.id = t.category_id WHERE t.id = team_fundraising.team_id AND c.school_id::text = ANY({$schools})))";
        DB::unprepared('ALTER TABLE team_fundraising ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE team_fundraising FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY team_fundraising_read ON team_fundraising FOR SELECT USING ({$system} OR {$opsAudit} OR {$memberOf} OR {$teacher} OR {$lobbyAdmin})");
        DB::unprepared("CREATE POLICY team_fundraising_insert ON team_fundraising FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY team_fundraising_update ON team_fundraising FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
    }

    public function down(): void
    {
        foreach (['read', 'insert', 'update'] as $k) {
            DB::unprepared("DROP POLICY IF EXISTS team_fundraising_{$k} ON team_fundraising");
        }
        Schema::dropIfExists('team_fundraising');
    }
};

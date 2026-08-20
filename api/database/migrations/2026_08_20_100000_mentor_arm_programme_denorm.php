<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-HYGIENE-1 item 1 (option A) — make the S-MENTOR-1 mentor arm resolve IDENTICALLY on all three reads.
 *
 * THE DEFECT. S-MENTOR-1 wrote ONE rule — (linked ∧ the programme flag) — from ONE closure, applied three
 * times. Its own docblock states the intent: "`programmes` has no RLS (a global table) → the flag is
 * readable inside every arm, for every actor." The flag is. The PATH to it is not:
 *   teams_read       → EXISTS (… FROM programmes p WHERE p.id = teams.programme_id …)        [no RLS]
 *   tm_read          → EXISTS (… FROM team_categories c JOIN programmes … c.id = …category_id …)
 *   stage_gates_read → same, via stage_gates.category_id
 * `team_categories` HAS RLS, and a policy's subquery is subject to the referenced table's own policies.
 * `team_categories_read` admits a teacher only via `school_id = ANY(app.school_ids)` (plus a
 * `school_id IS NULL` arm). So for a SCHOOL-BOUND lobby whose school the mentor is not linked to, the
 * category row is invisible, the EXISTS is false, and the arm collapses — while teams_read's arm passes.
 * The two category-route arms silently acquired a FOURTH condition (the mentor's own school scope) that
 * nobody wrote, imported from another table's policy and invisible at the point of authorship.
 *
 * Reproduced before this migration (same team, programme_id agreeing, category_id non-null):
 *   open lobby          → teams 1 · gates 2 · category visible 1 · direct arm t · category arm t
 *   school-bound lobby  → teams 1 · gates 0 · members 0 · category visible 0 · direct arm t · category arm f
 * Three symptoms, one cause: an all-false Activity Tracker, an empty roster, and (because
 * TeamMembersController probes membership under the caller's RLS before its elevation) a count-only
 * /teams/{id}/members. All three fail SILENTLY and plausibly rather than denying.
 *
 * Reachable in normal configuration: `teacher_links_active_unique` allows a teacher at most ONE active
 * school link, so a mentor of teams in two school-bound lobbies necessarily hits it for one of them, and an
 * academy-side mentor with no school link hits it for every school-bound lobby. It went unseen because no
 * seeded or demo dataset contains a school-bound lobby — the first one anywhere was built by a test.
 *
 * THE FIX (option A, owner-ruled): denormalise `programme_id` onto team_members and stage_gates — the same
 * remedy, for the same reason, as the `category_id` those tables already carry — and take the RLS-scoped
 * table out of the policy path. The rewritten arms become structurally identical to teams_read's. The
 * change is STRICTLY WIDENING and only for one actor shape: the old condition (linked ∧ category-visible ∧
 * flag) implies the new one (linked ∧ flag). teams_read is NOT touched here (its live definition is A-4's).
 *
 * ── BACKFILL: THE TRAP, AND WHY THE set_config BELOW IS MANDATORY ───────────────────────────────────────
 * Source is `teams`, never `team_categories`: the fixed arm must resolve identically to teams_read's, which
 * reads teams.programme_id. Nothing constrains teams.programme_id = its category's programme_id (two
 * independent FKs, no composite), so sourcing from the category could import that latent disagreement into
 * the new column and preserve the very divergence being removed. The guard below ABORTS on any mismatch
 * rather than picking a winner.
 *
 * No migration in this repo had previously backfilled an RLS-protected table. `tm_update` and
 * `stage_gates_update` are USING/WITH CHECK (app.context = 'system'). Staging and production migrate as
 * `kap_migrate` — the table OWNER but NOSUPERUSER NOBYPASSRLS — against FORCE ROW LEVEL SECURITY
 * (deploy/gcp/sql/01-roles-and-grants.sql); kap_test is owned by kap_app, likewise FORCE-subject. In both,
 * a plain UPDATE matches ZERO rows, silently. The local dev `kap` database is owned by the superuser `kap`
 * (BYPASSRLS), so the trap does NOT reproduce on a developer's machine. That asymmetry — green locally,
 * silently empty in CI and production — is exactly why the transaction-local context set is mandatory and
 * why the zero-NULL count runs BEFORE the SET NOT NULL rather than trusting it.
 *
 * BEHAVIOUR-SHA. teams_read must not move: f28e2e86d6c86c42f7a9b91e2c94e8c899ea0517b388b0caf44374186b9468a3.
 * stage_gates_read's PRE-CHANGE pin was 81e135f34f0715b2fb119f8c665447d2c5a20eac4f4d830673246fbc2a0454be;
 * it changes here (new pin in RolesTrackerTest). tm_read had no pin before and gains its first.
 *
 * ORDERING: this must land BEFORE the F-4 delegated-arm card reaches team_members / stage_gates, or that
 * card rebases onto changed source text.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. GUARD: refuse to paper over a real disagreement (finding (a) — nothing constrains it yet) ──
        $mismatch = DB::select('SELECT t.id, t.programme_id AS team_programme, c.programme_id AS category_programme
            FROM teams t JOIN team_categories c ON c.id = t.category_id
            WHERE c.programme_id <> t.programme_id');
        if ($mismatch !== []) {
            $ids = implode(', ', array_map(fn ($r) => "{$r->id} (team {$r->team_programme} ≠ lobby {$r->category_programme})", $mismatch));
            throw new RuntimeException("Refusing to backfill: team.programme_id disagrees with its lobby's on: {$ids}. Resolve the data first — the backfill must not pick a winner.");
        }

        // ── 2. GUARD: stage_gates.team_id carries no FK today; an orphan would fail the composite FK below.
        //        Surface it; a migration never deletes rows to make itself pass. ──
        $orphans = DB::select('SELECT sg.id, sg.team_id FROM stage_gates sg
            WHERE NOT EXISTS (SELECT 1 FROM teams t WHERE t.id = sg.team_id)');
        if ($orphans !== []) {
            $ids = implode(', ', array_map(fn ($r) => "{$r->id} → {$r->team_id}", $orphans));
            throw new RuntimeException("Refusing to add sg_team_programme_fk: stage_gates rows reference missing teams: {$ids}.");
        }

        // ── 3. the columns, nullable for the moment ──
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('programme_id')->nullable()->after('team_id')->constrained();
        });
        Schema::table('stage_gates', function (Blueprint $table) {
            $table->foreignId('programme_id')->nullable()->after('team_id')->constrained();
        });

        // ── 4. the backfill, under the system context (see the trap above). Transaction-local: Laravel runs
        //       a pgsql migration inside a transaction, so this resets at commit and leaks into nothing. ──
        DB::statement("SELECT set_config('app.context', 'system', true)");
        DB::statement('UPDATE team_members tm SET programme_id = t.programme_id FROM teams t WHERE t.id = tm.team_id');
        DB::statement('UPDATE stage_gates  sg SET programme_id = t.programme_id FROM teams t WHERE t.id = sg.team_id');

        // ── 5. prove the backfill actually ran BEFORE relying on SET NOT NULL to notice ──
        foreach (['team_members', 'stage_gates'] as $t) {
            $nulls = (int) DB::table($t)->whereNull('programme_id')->count();
            if ($nulls > 0) {
                throw new RuntimeException("Backfill did not populate {$t}.programme_id ({$nulls} row(s) still NULL) — the UPDATE was almost certainly filtered by RLS (app.context was not 'system').");
            }
        }

        DB::statement('ALTER TABLE team_members ALTER COLUMN programme_id SET NOT NULL');
        DB::statement('ALTER TABLE stage_gates  ALTER COLUMN programme_id SET NOT NULL');

        // ── 6. make the denormalisation SELF-DEFENDING. A write path that forgets to copy programme_id now
        //       fails loudly at insert time instead of silently re-creating the divergence. (The composite
        //       FK needs a unique key on the referenced pair; teams.id is already the PK, so this index is
        //       redundant by construction and costs one index.) The teams ↔ team_categories composite
        //       constraint that would close finding (a) is a SEPARATE card — different object. ──
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_id_programme_unique UNIQUE (id, programme_id)');
        DB::statement('ALTER TABLE team_members ADD CONSTRAINT tm_team_programme_fk
            FOREIGN KEY (team_id, programme_id) REFERENCES teams (id, programme_id)');
        DB::statement('ALTER TABLE stage_gates ADD CONSTRAINT sg_team_programme_fk
            FOREIGN KEY (team_id, programme_id) REFERENCES teams (id, programme_id)');

        // ── 7. the two policies. Every NON-mentor arm is reproduced character-for-character from
        //       2026_08_12_100000_mentor_team_access.php via the same clauses(); ONLY the configOn
        //       substring differs. teams_read is not touched (A-4 owns its live definition). ──
        [$system, $opsAudit, $actor, $role, $students, $schools, $schoolAdminOf, $mentorArm] = $this->clauses();

        $tmVisible = "{$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR EXISTS (SELECT 1 FROM team_categories c WHERE c.id = team_members.category_id
                       AND {$role} = 'school_admin' AND c.school_id::text = ANY({$schools}))";
        $tmMentor = $mentorArm('team_members.team_id', "EXISTS (SELECT 1 FROM programmes p WHERE p.id = team_members.programme_id AND p.mentor_team_access)");
        DB::unprepared('DROP POLICY tm_read ON team_members');
        DB::unprepared("CREATE POLICY tm_read ON team_members FOR SELECT USING ({$tmVisible} OR ({$tmMentor}))");

        $sgBase = "{$system} OR {$opsAudit} OR {$schoolAdminOf('stage_gates')}
            OR EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = stage_gates.team_id AND tm.status <> 'removed'
                       AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $sgMentor = $mentorArm('stage_gates.team_id', "EXISTS (SELECT 1 FROM programmes p WHERE p.id = stage_gates.programme_id AND p.mentor_team_access)");
        DB::unprepared('DROP POLICY stage_gates_read ON stage_gates');
        DB::unprepared("CREATE POLICY stage_gates_read ON stage_gates FOR SELECT USING ({$sgBase} OR ({$sgMentor}))");
    }

    public function down(): void
    {
        // Restore BOTH policies verbatim (the category route), then drop the constraints and the columns.
        [$system, $opsAudit, $actor, $role, $students, $schools, $schoolAdminOf, $mentorArm] = $this->clauses();

        $tmVisible = "{$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR EXISTS (SELECT 1 FROM team_categories c WHERE c.id = team_members.category_id
                       AND {$role} = 'school_admin' AND c.school_id::text = ANY({$schools}))";
        $tmMentor = $mentorArm('team_members.team_id', "EXISTS (SELECT 1 FROM team_categories c JOIN programmes p ON p.id = c.programme_id WHERE c.id = team_members.category_id AND p.mentor_team_access)");
        DB::unprepared('DROP POLICY tm_read ON team_members');
        DB::unprepared("CREATE POLICY tm_read ON team_members FOR SELECT USING ({$tmVisible} OR ({$tmMentor}))");

        $sgBase = "{$system} OR {$opsAudit} OR {$schoolAdminOf('stage_gates')}
            OR EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = stage_gates.team_id AND tm.status <> 'removed'
                       AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $sgMentor = $mentorArm('stage_gates.team_id', "EXISTS (SELECT 1 FROM team_categories c JOIN programmes p ON p.id = c.programme_id WHERE c.id = stage_gates.category_id AND p.mentor_team_access)");
        DB::unprepared('DROP POLICY stage_gates_read ON stage_gates');
        DB::unprepared("CREATE POLICY stage_gates_read ON stage_gates FOR SELECT USING ({$sgBase} OR ({$sgMentor}))");

        DB::statement('ALTER TABLE stage_gates DROP CONSTRAINT sg_team_programme_fk');
        DB::statement('ALTER TABLE team_members DROP CONSTRAINT tm_team_programme_fk');
        DB::statement('ALTER TABLE teams DROP CONSTRAINT teams_id_programme_unique');

        Schema::table('stage_gates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_id');
        });
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_id');
        });
    }

    /**
     * The S-MENTOR-1 clause vocabulary, character-for-character from
     * 2026_08_12_100000_mentor_team_access.php — same variable text, so every arm this migration
     * recreates other than the two mentor arms is byte-identical SQL.
     */
    private function clauses(): array
    {
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $schoolAdminOf = fn (string $tbl) => "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM team_categories c WHERE c.id = {$tbl}.category_id AND c.school_id::text = ANY({$schools})))";
        // linked (active team_teacher_links for this team + the acting teacher) ∧ configOn (the programme flag).
        $mentorArm = fn (string $teamIdCol, string $configOn): string => "EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = {$teamIdCol} AND ttl.status = 'active' AND ttl.teacher_id::text = {$actor}) AND {$configOn}";

        return [$system, $opsAudit, $actor, $role, $students, $schools, $schoolAdminOf, $mentorArm];
    }
};

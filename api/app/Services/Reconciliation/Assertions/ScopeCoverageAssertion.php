<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * Structural scope coverage (S02A, Leo amendment 2 + follow-ups):
 *  - every table in the schema must be classified in config/scope-map.php;
 *  - every 'scoped' table must have RLS enabled AND forced AND ≥1 policy;
 *  - every 'global' entry must carry a real written justification;
 *  - the connection must not be able to bypass RLS at all (superuser/BYPASSRLS
 *    would make every check above meaningless);
 *  - fail-closed probe: with no app context, a seeded scoped table yields 0 rows.
 * Tables added by later sprints are caught the night they appear.
 */
class ScopeCoverageAssertion implements Assertion
{
    private const PLACEHOLDERS = ['todo', 'tbd', 'n/a', 'na', 'placeholder', 'fixme', 'xxx', '-', 'because', 'reasons'];

    public function key(): string
    {
        return 'scope.coverage';
    }

    public function proves(): string
    {
        return 'every table is classified; every scoped table is RLS-forced with policies; every global entry carries a real justification; the runtime role cannot bypass RLS; scoped tables fail closed';
    }

    public function cites(): string
    {
        return 'FR006 · FR056 · S02A';
    }

    public function tags(): array
    {
        return ['S02A'];
    }

    public function check(): AssertionResult
    {
        if (DB::getDriverName() !== 'pgsql') {
            return AssertionResult::fail('requires pgsql — run against the platform database');
        }

        $failures = [];

        // 0 — the connection itself must be RLS-subject
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        if ($role->rolsuper || $role->rolbypassrls) {
            return AssertionResult::fail(
                "connection role '{$role->name}' is superuser/BYPASSRLS — RLS is inert; run as the app role"
            );
        }

        $map = config('scope-map');
        $classified = array_merge(
            $map['scoped'],
            array_keys($map['global']),
            $map['infrastructure'],
        );

        $tables = collect(DB::select(
            "SELECT c.relname AS table, c.relrowsecurity, c.relforcerowsecurity
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind = 'r'"
        ));

        // 1 — no unclassified tables (the sprint adding a table classifies it)
        foreach ($tables->pluck('table') as $table) {
            if (! in_array($table, $classified, true)) {
                $failures[] = "table '{$table}' is UNCLASSIFIED in config/scope-map.php";
            }
        }
        foreach ($classified as $table) {
            if (! $tables->pluck('table')->contains($table)) {
                $failures[] = "map entry '{$table}' has no matching table (stale map)";
            }
        }

        // 2 — scoped ⇒ RLS enabled + forced + ≥1 policy
        $policyCounts = collect(DB::select(
            "SELECT tablename, count(*) AS n FROM pg_policies WHERE schemaname = 'public' GROUP BY tablename"
        ))->pluck('n', 'tablename');
        foreach ($map['scoped'] as $table) {
            $row = $tables->firstWhere('table', $table);
            if ($row === null) {
                continue; // reported above
            }
            if (! $row->relrowsecurity || ! $row->relforcerowsecurity) {
                $failures[] = "scoped table '{$table}' lacks ENABLE+FORCE row level security";
            }
            if (($policyCounts[$table] ?? 0) < 1) {
                $failures[] = "scoped table '{$table}' has NO policies";
            }
        }

        // 3 — global entries need real, recorded reasoning (Leo: the escape hatch)
        foreach ($map['global'] as $table => $reason) {
            $trimmed = strtolower(trim((string) $reason));
            if (mb_strlen($trimmed) < 30 || in_array($trimmed, self::PLACEHOLDERS, true)) {
                $failures[] = "global entry '{$table}' lacks a real justification ('{$reason}')";
            }
        }

        // 4 — fail-closed probe: no context ⇒ zero rows on a seeded scoped table
        $probe = DB::selectOne(
            "SELECT (SELECT count(*) FROM guardian_links) AS visible,
                    current_setting('app.context', true) AS ctx"
        );
        // (runner executes under system context; drop to none for the probe)
        DB::statement("SELECT set_config('app.context', '', false)");
        try {
            $visible = (int) DB::selectOne('SELECT count(*) AS n FROM guardian_links')->n;
            if ($visible !== 0) {
                $failures[] = "fail-closed VIOLATED: {$visible} guardian_links row(s) visible with NO context";
            }
        } finally {
            DB::statement("SELECT set_config('app.context', 'system', false)");
        }

        if ($failures !== []) {
            return AssertionResult::fail(implode('; ', array_slice($failures, 0, 8)).(count($failures) > 8 ? ' …' : ''));
        }

        return AssertionResult::pass(sprintf(
            '%d tables classified (%d scoped, all RLS-forced with policies; %d global, all justified); role %s is RLS-subject; fail-closed probe returned 0 rows',
            $tables->count(), count($map['scoped']), count($map['global']), $role->name,
        ));
    }
}

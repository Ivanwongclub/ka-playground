<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04C STEP 1 (D-iii/D-iv) — the structural confinement of the platform's first
 * anonymous WRITE. The public context is the analogue of the payment link's
 * single_reader: it must be provably boxed in, not trusted to behave.
 *
 * Four things, all read from the live catalog (pg_policies / pg_class):
 *  (a) EXACTLY ONE policy platform-wide references the `public` context — no
 *      second table, no read policy, no accidental second admittance. A planted
 *      policy admitting public anywhere else turns this red.
 *  (b) that one policy is on registration_requests, and it is an INSERT policy
 *      (public may write, never read — no enumeration oracle).
 *  (c) the WITH CHECK constrains WHAT public inserts (status = 'submitted'),
 *      so the anonymous write cannot craft an approved/privileged row.
 *  (d) registration_requests is RLS-forced (owners obey too).
 */
class PublicContextConfinementAssertion implements Assertion
{
    public function key(): string
    {
        return 'scope.public_context_confinement';
    }

    public function proves(): string
    {
        return 'the anonymous `public` context is admitted by exactly one policy platform-wide — the registration_requests INSERT — which constrains what it may write, and it can read nothing (no enumeration oracle)';
    }

    public function cites(): string
    {
        return 'OD-23 · D-iii · S04C STEP 1';
    }

    public function tags(): array
    {
        return ['S04C'];
    }

    public function check(): AssertionResult
    {
        $failures = [];

        // Every policy whose USING or WITH CHECK references the public context.
        // The comparison renders as = 'public'::text in pg_policies, so the
        // 'public' literal appears; schema/owner 'public' never appears in qual.
        $publicPolicies = DB::select("SELECT tablename, policyname, cmd, coalesce(with_check,'') AS with_check
            FROM pg_policies
            WHERE coalesce(qual,'') LIKE '%''public''%' OR coalesce(with_check,'') LIKE '%''public''%'");

        // (a) exactly one
        if (count($publicPolicies) !== 1) {
            $where = array_map(fn ($p) => "{$p->policyname} on {$p->tablename} ({$p->cmd})", $publicPolicies);
            return AssertionResult::fail(
                'the public context is admitted by '.count($publicPolicies).' policies — expected exactly 1: ['.implode(', ', $where).']'
            );
        }

        $p = $publicPolicies[0];

        // (b) the one policy is the registration_requests INSERT
        if ($p->tablename !== 'registration_requests') {
            $failures[] = "the sole public policy is on {$p->tablename}, not registration_requests";
        }
        // pg_policies.cmd is 'INSERT' for a FOR INSERT policy (or 'ALL')
        if (! in_array($p->cmd, ['INSERT', 'ALL'], true)) {
            $failures[] = "the sole public policy is a {$p->cmd} policy — public must WRITE only, never read (cmd must be INSERT)";
        }

        // (c) WITH CHECK constrains what public may insert (no privilege escalation).
        // pg renders the pin as "(status)::text = 'submitted'::text" — match the literal.
        if (! str_contains($p->with_check, "= 'submitted'")) {
            $failures[] = 'the public INSERT policy does not pin status = submitted — an anonymous write could craft a privileged row';
        }

        // (d) registration_requests is RLS-forced
        $forced = DB::selectOne("SELECT relrowsecurity AND relforcerowsecurity AS ok
            FROM pg_class WHERE relname = 'registration_requests' AND relkind = 'r'");
        if ($forced === null || ! $forced->ok) {
            $failures[] = 'registration_requests is not RLS-forced';
        }

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures))
            : AssertionResult::pass('one public policy platform-wide: registration_requests INSERT, status-pinned, RLS-forced; public reads nothing');
    }
}

<?php

namespace App\Services\Authz;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The RLS session context (S02A step 3). Derived EXCLUSIVELY from the
 * authenticated user's database links — never from request input of any kind
 * (headers, body, query, cookies, session payload). Forged input has no path
 * here: set() takes a User already resolved by Sanctum, and every id list is
 * read from the links tables server-side.
 *
 * Lifecycle is structural (registered once in ScopeContextServiceProvider):
 *   HTTP    middleware sets on entry, terminate() resets
 *   Queue   Queue::before resets-then-sets system; after/failing reset
 *   Console CommandStarting resets then sets system
 * 'system' is settable only by those bootstrap paths — no HTTP request can
 * claim it. Absent context ⇒ RLS policies match nothing (fail-closed).
 */
class ScopeContext
{
    /** Set the context for an authenticated user. */
    public function set(User $user): void
    {
        // Derivation reads links/capabilities BEFORE the user context exists;
        // it runs under a momentary internal system context, entirely server-side.
        $this->setSystem();

        $schoolIds = [];
        $studentIds = [];
        $capabilities = [];

        switch ($user->role) {
            case 'school_admin':
                $schoolIds = DB::table('school_admin_links')
                    ->where('school_admin_id', $user->id)->where('status', 'active')
                    ->pluck('school_id')->all();
                // A-3: fold the effective delegated capabilities (A-2 baseline grants + programme grant-
                // overrides, request-wide superset ∩ A-1 delegable) into the SAME app.capabilities list.
                // No policy reads them until A-4. Permission-key namespace — never collides with the
                // academy_admin capability-GROUP names that also live in this GUC (groups are dot-free).
                $capabilities = app(EffectiveCapabilityResolver::class)->capabilitiesForGuc($user);
                break;
            case 'teacher':
                $schoolIds = DB::table('teacher_links')
                    ->where('teacher_id', $user->id)->where('status', 'active')
                    ->pluck('school_id')->all();
                // A-3: same effective-capability derivation as school_admin (request-wide superset).
                $capabilities = app(EffectiveCapabilityResolver::class)->capabilitiesForGuc($user);
                break;
            case 'guardian':
                $studentIds = DB::table('guardian_links')
                    ->where('guardian_id', $user->id)->where('status', 'active')
                    ->pluck('student_id')->all();
                break;
            case 'student':
                $schoolIds = DB::table('school_links')
                    ->where('student_id', $user->id)->where('status', 'active')
                    ->pluck('school_id')->all();
                break;
            case 'academy_admin':
                $capabilities = DB::table('admin_capabilities')
                    ->where('user_id', $user->id)->whereNull('revoked_at')
                    ->pluck('capability')->all();
                break;
            case 'member':
                // OD-22/R-Member: a Member is NETWORK-scoped, not link-scoped. There is
                // no school/student/capability to derive — the marker is actor_role='member'
                // itself, which the events/directory policies key on. Everything else stays
                // empty, so a Member matches no enrolment/consent/finance/team row.
                break;
        }

        $this->apply([
            'app.context' => 'request',
            'app.actor_id' => (string) $user->id,
            'app.actor_role' => $user->role,
            'app.capabilities' => implode(',', $capabilities),
            'app.school_ids' => implode(',', $schoolIds),
            'app.student_ids' => implode(',', $studentIds),
        ]);
    }

    /** Platform context: queue workers, console, scheduler. Never HTTP. */
    public function setSystem(): void
    {
        $this->apply([
            'app.context' => 'system',
            'app.actor_id' => '',
            'app.actor_role' => '',
            'app.capabilities' => '',
            'app.school_ids' => '',
            'app.student_ids' => '',
        ]);
    }

    /**
     * The anonymous WRITE context (S04C, D-iii). The LEAST-privileged context in
     * the system: no actor, no role, no scope — it matches exactly ONE policy
     * platform-wide, the registration_requests INSERT (proved by
     * scope.public_context_confinement). It reads NOTHING (no enumeration
     * oracle) and cannot escalate through its one insert (WITH CHECK). Unlike
     * setSystem() this is a DE-escalation, so it carries no elevation allowlist;
     * the business action it wraps is audited (registration.submitted, role
     * 'public'). Set only around the registration write, reset immediately after.
     */
    public function setPublic(): void
    {
        $this->apply([
            'app.context' => 'public',
            'app.actor_id' => '',
            'app.actor_role' => '',
            'app.capabilities' => '',
            'app.school_ids' => '',
            'app.student_ids' => '',
        ]);
    }

    /**
     * Run a platform integrity check under system context, restoring the
     * caller's context after. THE SANCTIONED BYPASS — constrained (Leo, S02A):
     * the call site must appear in config/scope-elevations.php with a matching
     * reason, or this throws; every elevation writes a scope.elevated audit
     * event carrying the call site and reason.
     */
    public function asSystem(string $reason, \Closure $fn): mixed
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
        $caller = ($frame['class'] ?? '?').'::'.($frame['function'] ?? '?');

        $allowlist = config('scope-elevations', []);
        if (! array_key_exists($caller, $allowlist)) {
            throw new \LogicException(
                "asSystem() call site '{$caller}' is NOT in the scope-elevations allowlist — declare it with a reason or do not elevate"
            );
        }
        if ($allowlist[$caller] !== $reason) {
            throw new \LogicException(
                "asSystem() reason at '{$caller}' does not match the declared allowlist reason"
            );
        }

        $saved = $this->snapshot();
        $this->setSystem();
        try {
            $result = $fn();
            // Audited AFTER the closure, still under system (scoped reads inside
            // the elevation are what we are recording)
            app(\App\Services\Audit\AuditService::class)->record(
                'scope_elevation', $caller, 'scope.elevated',
                reason: $reason,
                payloadAfter: ['caller' => $caller, 'restored_context' => $saved['app.context'] ?? ''],
                actor: \Illuminate\Support\Facades\Auth::user(),
            );

            return $result;
        } finally {
            $this->restore($saved);
        }
    }

    /** @var array<int, array<string, string>> */
    private array $jobStack = [];

    /**
     * Job boundary (S02A lifecycle, repaired in S03-3): jobs always RUN as
     * system, but the boundary restores whatever context surrounded the job —
     * a worker's snapshot is empty (stays scrubbed, exactly as before), while
     * a sync-driver job running inline inside an HTTP request must hand the
     * requester's context back instead of wiping the rest of the request.
     */
    public function beginJob(): void
    {
        $this->jobStack[] = $this->snapshot();
        $this->reset();
        $this->setSystem();
    }

    public function endJob(): void
    {
        $saved = array_pop($this->jobStack);
        $isEmpty = $saved === null || $saved === [] || array_filter($saved) === [];
        if ($isEmpty) {
            $this->reset();
        } else {
            $this->restore($saved);
        }
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }
        $row = (array) DB::selectOne(
            "SELECT current_setting('app.context', true) AS \"app.context\",
                    current_setting('app.actor_id', true) AS \"app.actor_id\",
                    current_setting('app.actor_role', true) AS \"app.actor_role\",
                    current_setting('app.capabilities', true) AS \"app.capabilities\",
                    current_setting('app.school_ids', true) AS \"app.school_ids\",
                    current_setting('app.student_ids', true) AS \"app.student_ids\""
        );

        return array_map(fn ($v) => (string) ($v ?? ''), $row);
    }

    /** @param array<string, string> $vars */
    private function restore(array $vars): void
    {
        if ($vars === []) {
            return;
        }
        $this->apply($vars);
    }

    /** Scrub everything — the fail-closed ground state. */
    public function reset(): void
    {
        // Self-guarding: reset() must NEVER require a database. It is called at
        // request boundaries AND by the console scope lifecycle, which fires during
        // build-time commands (composer's package:discover, key:generate, the deploy
        // image's config:cache) that run before the DB/runtime role exists. If no
        // usable pgsql connection is available, do nothing — fail-closed (absent
        // context matches nothing under RLS), never a crash. This makes the
        // guarantee hold at the call site itself, independent of any listener guard.
        if (! $this->databaseAvailable()) {
            return;
        }
        DB::statement("SELECT set_config('app.context', '', false), set_config('app.actor_id', '', false), set_config('app.actor_role', '', false), set_config('app.capabilities', '', false), set_config('app.school_ids', '', false), set_config('app.student_ids', '', false)");
    }

    /**
     * Is a usable pgsql connection available right now?
     *
     * Gates the CONSOLE scope lifecycle (ScopeContextServiceProvider): build-time
     * commands — composer's `package:discover`, the deploy image's config caching —
     * run before the database/credentials exist and touch no application data, so
     * they must NOT require a connection just to start. Returns false on ANY
     * connection/auth failure (e.g. the runtime role not created yet), so such a
     * command runs WITHOUT system context — which is fail-CLOSED (absent context
     * makes every RLS policy match nothing), never a leak. A command that truly
     * needs the DB surfaces its own connection error on its first real query.
     */
    public function databaseAvailable(): bool
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'pgsql') {
                return false;
            }
            $connection->getPdo(); // forces a connect; throws if unreachable/unauthenticated

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, string> $vars */
    private function apply(array $vars): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $sql = collect($vars)
            ->map(fn ($v, $k) => 'set_config('.DB::connection()->getPdo()->quote($k).', '.DB::connection()->getPdo()->quote($v).', false)')
            ->implode(', ');
        try {
            DB::statement("SELECT {$sql}");
        } catch (\Illuminate\Database\QueryException $e) {
            // 25P02: the surrounding transaction is already aborted. GUC writes
            // are impossible there AND unnecessary — rollback reverts every GUC
            // set inside the transaction, so fail-closed state is preserved.
            // Swallowing anything else would weaken the scope layer; rethrow.
            if (($e->errorInfo[0] ?? '') !== '25P02') {
                throw $e;
            }
        }
    }
}

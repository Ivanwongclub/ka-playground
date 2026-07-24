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
                break;
            case 'teacher':
                $schoolIds = DB::table('teacher_links')
                    ->where('teacher_id', $user->id)->where('status', 'active')
                    ->pluck('school_id')->all();
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
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("SELECT set_config('app.context', '', false), set_config('app.actor_id', '', false), set_config('app.actor_role', '', false), set_config('app.capabilities', '', false), set_config('app.school_ids', '', false), set_config('app.student_ids', '', false)");
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
        DB::statement("SELECT {$sql}");
    }
}

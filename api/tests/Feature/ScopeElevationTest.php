<?php

namespace Tests\Feature;

use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ScopeElevationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlisted_call_site_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NOT in the scope-elevations allowlist');

        app(ScopeContext::class)->asSystem('any reason', fn () => true);
    }

    public function test_reason_mismatch_throws_even_for_a_listed_site(): void
    {
        config()->set('scope-elevations', [
            static::class.'::'.__FUNCTION__ => 'the declared reason',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not match the declared allowlist reason');

        app(ScopeContext::class)->asSystem('a different reason', fn () => true);
    }

    public function test_sanctioned_elevation_runs_and_audits(): void
    {
        $caller = static::class.'::'.__FUNCTION__;
        config()->set('scope-elevations', [$caller => 'test-sanctioned elevation for the audit-trail check']);

        $result = app(ScopeContext::class)->asSystem(
            'test-sanctioned elevation for the audit-trail check',
            fn () => 'ran',
        );

        $this->assertSame('ran', $result);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'scope.elevated',
            'entity_type' => 'scope_elevation',
            'entity_id' => $caller,
            'reason' => 'test-sanctioned elevation for the audit-trail check',
        ]);
    }

    public function test_every_asSystem_call_site_in_the_codebase_is_allowlisted(): void
    {
        // The CI guard (Leo, S02A gate review): a NEW ->asSystem( call site
        // anywhere in app/ that is not declared in config/scope-elevations.php
        // fails this test — justifications live on the record, not in memory.
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (! str_contains($source, '->asSystem(')) {
                continue;
            }
            preg_match('/namespace\s+([^;]+);/', $source, $ns);
            preg_match('/class\s+(\w+)/', $source, $cls);
            $fqcn = trim($ns[1] ?? '').'\\'.($cls[1] ?? '');

            // Attribute each occurrence to its enclosing method
            $offset = 0;
            while (($pos = strpos($source, '->asSystem(', $offset)) !== false) {
                $before = substr($source, 0, $pos);
                preg_match_all('/function\s+(\w+)\s*\(/', $before, $fns);
                $method = end($fns[1]) ?: '?';
                $found[] = "{$fqcn}::{$method}";
                $offset = $pos + 1;
            }
        }
        $found = array_values(array_unique(array_filter(
            $found,
            fn ($site) => $site !== \App\Services\Authz\ScopeContext::class.'::asSystem',
        )));

        $allowlisted = array_keys(config('scope-elevations'));
        sort($found);
        sort($allowlisted);

        $this->assertSame(
            $allowlisted,
            $found,
            'asSystem() call sites and the scope-elevations allowlist have diverged — declare the new site with a reason, or remove the stale entry',
        );
    }
}

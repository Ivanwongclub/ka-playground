<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The test HARNESS is the platform: its own factories and assertions run
     * under the system RLS context. Each simulated request still sets and
     * scrubs its own context via the real middleware; the harness re-establishes
     * system afterwards so post-request assertions aren't fail-closed to zero.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\App\Services\Authz\ScopeContext::class)->setSystem();
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $this->app->make(\App\Services\Authz\ScopeContext::class)->setSystem();

        return $response;
    }
}

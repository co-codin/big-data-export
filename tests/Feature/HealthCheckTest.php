<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_returns_success_when_deps_are_reachable(): void
    {
        $this->artisan('health:check')
            ->expectsOutput('OK')
            ->assertExitCode(0);
    }
}

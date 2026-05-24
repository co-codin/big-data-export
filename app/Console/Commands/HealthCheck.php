<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness probe used by docker-compose healthchecks on the app + worker
 * containers. Verifies the two external dependencies a running container
 * actually needs to do useful work: Postgres and Redis. Exits 0 on
 * success, 1 on any failure.
 *
 * NOT a full health check — does not catch a worker that's silently
 * crashing on every job. For that, schedule `queue:monitor reports`
 * to alert on backlog, or read make queue-status.
 */
class HealthCheck extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Verify the app/worker can reach Postgres + Redis.';

    public function handle(): int
    {
        try {
            DB::connection()->select('SELECT 1');
        } catch (Throwable $e) {
            $this->error('db: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $this->error('redis: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('OK');

        return self::SUCCESS;
    }
}

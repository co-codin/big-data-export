<?php

namespace App\Console\Commands;

use App\Repositories\ReportProcessRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Janitor for the "stuck in Запуск" edge case: a worker that crashes
 * between completing a chunk and the Bus::batch finally callback firing
 * leaves the report_process row with no terminal state.
 *
 * Intended to be scheduled (every few minutes) — see routes/console.php.
 * Manual run is fine too: `php artisan reports:reap-stuck`.
 */
class ReapStuckReports extends Command
{
    protected $signature = 'reports:reap-stuck
        {--minutes=30 : Rows in Запуск older than this are flipped to Ошибка}';

    protected $description = 'Mark report_process rows stuck in Запуск longer than --minutes as Ошибка.';

    public function handle(ReportProcessRepository $repo): int
    {
        $minutes = (int) $this->option('minutes');
        if ($minutes <= 0) {
            $this->error('--minutes must be a positive integer.');

            return self::INVALID;
        }

        $cutoff = Carbon::now()->subMinutes($minutes);
        $count = $repo->markStuckAsError($cutoff);

        if ($count > 0) {
            Log::warning('reports:reap-stuck flipped stuck rows', [
                'count' => $count,
                'cutoff' => $cutoff->toIso8601String(),
            ]);
        }

        $this->info("Reaped {$count} row(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReapStuckReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flips_old_started_rows_to_error_and_leaves_others_alone(): void
    {
        // 35 min old, in Запуск → should be reaped
        $stuck = ReportProcess::factory()->started()->create([
            'rp_start_datetime' => Carbon::now()->subMinutes(35),
        ]);
        // 2 min old, in Запуск → should NOT be reaped (within window)
        $recent = ReportProcess::factory()->started()->create([
            'rp_start_datetime' => Carbon::now()->subMinutes(2),
        ]);
        // 35 min old but Завершен → should NOT be touched
        $completed = ReportProcess::factory()->completed('reports/x.csv')->create([
            'rp_start_datetime' => Carbon::now()->subMinutes(35),
        ]);
        // 35 min old already Ошибка → should NOT be touched
        $errored = ReportProcess::factory()->errored()->create([
            'rp_start_datetime' => Carbon::now()->subMinutes(35),
        ]);

        $this->artisan('reports:reap-stuck')
            ->expectsOutputToContain('Reaped 1 row(s)')
            ->assertExitCode(0);

        $this->assertSame(ProcessStatusId::Error, $stuck->fresh()->ps_id);
        $this->assertSame(ProcessStatusId::Started, $recent->fresh()->ps_id);
        $this->assertSame(ProcessStatusId::Completed, $completed->fresh()->ps_id);
        $this->assertSame(ProcessStatusId::Error, $errored->fresh()->ps_id);
    }

    public function test_no_rows_to_reap_still_exits_success(): void
    {
        ReportProcess::factory()->started()->create([
            'rp_start_datetime' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reports:reap-stuck')
            ->expectsOutputToContain('Reaped 0 row(s)')
            ->assertExitCode(0);
    }

    public function test_custom_minutes_option_narrows_or_widens_the_window(): void
    {
        ReportProcess::factory()->started()->create([
            'rp_start_datetime' => Carbon::now()->subMinutes(10),
        ]);

        // 30-min window (default) leaves it alone
        $this->artisan('reports:reap-stuck')
            ->expectsOutputToContain('Reaped 0 row(s)')
            ->assertExitCode(0);

        // 5-min window catches it
        $this->artisan('reports:reap-stuck', ['--minutes' => 5])
            ->expectsOutputToContain('Reaped 1 row(s)')
            ->assertExitCode(0);
    }

    public function test_non_positive_minutes_is_rejected(): void
    {
        $this->artisan('reports:reap-stuck', ['--minutes' => 0])
            ->assertExitCode(2);
    }
}

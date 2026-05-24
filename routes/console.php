<?php

use App\Console\Commands\ReapStuckReports;
use Illuminate\Support\Facades\Schedule;

// Custom console commands live in app/Console/Commands and are auto-discovered.

// Janitor for the "stuck in Запуск" edge case: every 5 minutes, mark any
// row still in Запуск older than 30 minutes as Ошибка. Requires either a
// long-running `php artisan schedule:work` process or a cron entry that
// runs `php artisan schedule:run` every minute.
Schedule::command(ReapStuckReports::class)->everyFiveMinutes()->withoutOverlapping();

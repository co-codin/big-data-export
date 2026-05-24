<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Postgres `dateTime()` defaults to timestamp(0) — second-precision.
 * That truncates milliseconds in $process->rp_start_datetime, which
 * makes rp_exec_time round to whole seconds for fast runs. Widening to
 * timestamp(3) (millisecond precision) keeps wall-clock honest.
 *
 * Doctrine DBAL isn't installed so we use raw SQL for the column type
 * change. Postgres preserves existing values across the precision shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_process ALTER COLUMN rp_start_datetime TYPE timestamp(3) without time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_process ALTER COLUMN rp_start_datetime TYPE timestamp(0) without time zone');
    }
};

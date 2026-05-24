<?php

namespace App\Services;

/**
 * Per-manufacturer entry in DispatchResult::$reports.
 * One of these per (manufacturer, category) pair that successfully got
 * a report_process row + chunk jobs dispatched.
 */
class DispatchedReport
{
    public function __construct(
        public readonly int $rpId,
        public readonly int $manufacturerId,
        public readonly int $chunkCount,
    ) {}
}

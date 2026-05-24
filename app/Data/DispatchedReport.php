<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * Per-manufacturer entry in DispatchResult::$reports.
 * One of these per (manufacturer, category) pair that successfully got
 * a report_process row + chunk jobs dispatched.
 *
 * Extends Spatie\LaravelData\Data so the object hydrates from / dumps to
 * arrays + JSON for free — useful once a future HTTP endpoint returns
 * a DispatchResult as the API response.
 */
class DispatchedReport extends Data
{
    public function __construct(
        public readonly int $rpId,
        public readonly int $manufacturerId,
        public readonly int $chunkCount,
    ) {}
}

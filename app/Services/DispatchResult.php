<?php

namespace App\Services;

/**
 * Outcome of ReportDispatcher::dispatchForCategory(). Plain readonly value
 * object so the command can render output without re-querying anything.
 */
class DispatchResult
{
    /**
     * @param  bool  $emptyCategory  true when the requested category had no products
     * @param  list<array{rp_id:int,manufacturer_id:int,chunk_count:int}>  $reports
     *                                                                               one entry per (manufacturer, category)
     *                                                                               pair that got a report_process row + jobs
     * @param  bool  $sync  whether the pipeline ran inline (--sync) or via the queue
     */
    public function __construct(
        public readonly bool $emptyCategory,
        public readonly array $reports,
        public readonly bool $sync,
    ) {}
}

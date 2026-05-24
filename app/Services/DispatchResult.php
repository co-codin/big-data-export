<?php

namespace App\Services;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Outcome of ReportDispatcher::dispatchForCategory(). Plain readonly value
 * object so the command can render output without re-querying anything.
 *
 * Extends Spatie\LaravelData\Data so the object hydrates from / dumps to
 * arrays + JSON for free; #[DataCollectionOf] tells the package that the
 * `$reports` array contains DispatchedReport instances (not plain arrays).
 */
class DispatchResult extends Data
{
    /**
     * @param  bool  $emptyCategory  true when the requested category had no products
     * @param  list<DispatchedReport>  $reports  one entry per (manufacturer, category)
     *                                           pair that got a report_process row + jobs
     * @param  bool  $sync  whether the pipeline ran inline (--sync) or via the queue
     */
    public function __construct(
        public readonly bool $emptyCategory,
        #[DataCollectionOf(DispatchedReport::class)]
        public readonly array $reports,
        public readonly bool $sync,
    ) {}
}

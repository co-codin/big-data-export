<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReportRequest;
use App\Services\ReportDispatcher;
use Illuminate\Http\RedirectResponse;

/**
 * HTTP entry point for dispatching new reports. Mirrors what the
 * `report:generate` artisan command does — both call the same
 * ReportDispatcher::dispatchForCategory().
 *
 * Returns a redirect back to the control page with a flash message
 * (success or error). No JSON, no JS — the Blade form POSTs here.
 */
class ReportController
{
    public function __construct(private readonly ReportDispatcher $dispatcher) {}

    public function store(StoreReportRequest $request): RedirectResponse
    {
        $categoryId = $request->categoryId();
        $result = $this->dispatcher->dispatchForCategory($categoryId);

        if ($result->emptyCategory) {
            return back()->withErrors([
                'category_id' => "Для категории {$categoryId} не найдено товаров.",
            ]);
        }

        $rpIds = implode(', ', array_map(fn ($r) => $r->rpId, $result->reports));
        $count = count($result->reports);

        return back()->with(
            'success',
            "Отчёт поставлен в очередь — производителей: {$count}, rp_id: {$rpIds}."
        );
    }
}

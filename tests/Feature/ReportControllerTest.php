<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportChunkJob;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ReportProcess;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_reports_validates_category_id(): void
    {
        // Missing
        $this->from(route('processes.index'))
            ->post(route('reports.store'), [])
            ->assertRedirect(route('processes.index'))
            ->assertSessionHasErrors(['category_id']);

        // Non-positive
        $this->from(route('processes.index'))
            ->post(route('reports.store'), ['category_id' => 0])
            ->assertRedirect(route('processes.index'))
            ->assertSessionHasErrors(['category_id']);

        // Non-integer
        $this->from(route('processes.index'))
            ->post(route('reports.store'), ['category_id' => 'abc'])
            ->assertRedirect(route('processes.index'))
            ->assertSessionHasErrors(['category_id']);
    }

    public function test_post_reports_empty_category_redirects_back_with_error_flash(): void
    {
        Bus::fake();

        $this->from(route('processes.index'))
            ->post(route('reports.store'), ['category_id' => 999])
            ->assertRedirect(route('processes.index'))
            ->assertSessionHasErrors(['category_id' => 'Для категории 999 не найдено товаров.']);

        // Empty category still creates an Ошибка row (per the dispatcher)
        $this->assertSame(1, ReportProcess::count());
        Bus::assertNothingBatched();
    }

    public function test_post_reports_dispatches_and_redirects_back_with_success_flash(): void
    {
        Bus::fake();

        $mfr = Manufacturer::factory()->create();
        Product::factory()->for($mfr)->create(['category_id' => 7]);

        $response = $this->from(route('processes.index'))
            ->post(route('reports.store'), ['category_id' => 7]);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('success', function ($flash) {
            return str_contains($flash, 'Отчёт поставлен в очередь')
                && str_contains($flash, 'производителей: 1');
        });

        // Exactly one Запуск row + one dispatched batch of chunk jobs
        $this->assertSame(1, ReportProcess::count());
        Bus::assertBatchCount(1);
        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->jobs->every(fn ($j) => $j instanceof GenerateReportChunkJob);
        });
    }

    public function test_get_index_renders_the_dispatch_form(): void
    {
        $response = $this->get(route('processes.index'));

        $response->assertOk();
        $response->assertSee('action="'.route('reports.store').'"', false);
        $response->assertSee('name="category_id"', false);
        $response->assertSee('Сформировать отчёт');
    }
}

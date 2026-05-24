<?php

namespace App\Console\Commands;

use App\Services\ReportDispatcher;
use Illuminate\Console\Command;

class GenerateReport extends Command
{
    protected $signature = 'report:generate
        {category_id : Идентификатор категории товара}
        {--sync : Run all chunk jobs + finalize inline (no queue, useful for debugging)}';

    protected $description = 'Поставить в очередь Redis Bus-batch заданий на формирование CSV-отчёта (с чанками по продуктам).';

    public function __construct(private readonly ReportDispatcher $dispatcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $categoryId = (int) $this->argument('category_id');
        if ($categoryId <= 0) {
            $this->error('category_id должен быть положительным целым числом.');

            return self::INVALID;
        }

        $result = $this->dispatcher->dispatchForCategory(
            $categoryId,
            (bool) $this->option('sync'),
        );

        if ($result->emptyCategory) {
            $this->error("Ошибка: для категории {$categoryId} не найдено товаров.");

            return self::FAILURE;
        }

        $verb = $result->sync ? 'выполнено синхронно' : 'поставлено в очередь';
        foreach ($result->reports as $report) {
            $this->info(
                "rp_id={$report['rp_id']}: {$verb} ".
                "(manufacturer={$report['manufacturer_id']}, чанков={$report['chunk_count']})"
            );
        }

        return self::SUCCESS;
    }
}

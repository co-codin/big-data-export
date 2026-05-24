<?php

namespace App\Console\Commands;

use App\Services\ReportDispatcher;
use Illuminate\Console\Command;

class GenerateReport extends Command
{
    protected $signature = 'report:generate
        {category_id : Идентификатор категории товара}';

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

        $result = $this->dispatcher->dispatchForCategory($categoryId);

        if ($result->emptyCategory) {
            $this->error("Ошибка: для категории {$categoryId} не найдено товаров.");

            return self::FAILURE;
        }

        foreach ($result->reports as $report) {
            $this->info(
                "rp_id={$report->rpId}: поставлено в очередь ".
                "(manufacturer={$report->manufacturerId}, чанков={$report->chunkCount})"
            );
        }

        return self::SUCCESS;
    }
}

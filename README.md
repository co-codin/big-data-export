# Report App

Laravel 11 + PostgreSQL + Redis приложение для асинхронного формирования
CSV-отчёта по товарам выбранной категории с минимальной и максимальной ценой
за последние 7 дней, ведением журнала выполнения и страницей контроля.

## Стек

- PHP 8.2
- Laravel 11
- PostgreSQL 16
- Redis 7 (очередь Laravel)
- Docker / Docker Compose
- Makefile

## Архитектура

```
+------------------+    1. INSERT report_process (status=Запуск)
| artisan          |    2. dispatch GenerateReportJob
| report:generate  +---------+
| {category_id}    |         |
+------------------+         v
                       +-----+------+
                       |   Redis    |   list: report_app_database_queues:reports
                       +-----+------+
                             |
                             v
                       +------------+
                       |  queue     |  3. resolve + run job (streaming CSV)
                       |  :work     |  4. UPDATE report_process (status=Завершен/Ошибка)
                       +------------+
```

CLI команда — это **продьюсер**. Длинная работа делается в Laravel-джобе
`App\Jobs\GenerateReportJob`, которая обрабатывается отдельным контейнером-воркером,
запускающим `php artisan queue:work redis --queue=reports`.

### Почему так масштабируется до 100 млн записей

- **Чанкование на уровне продьюсера**: команда `report:generate` делит товары
  каждого производителя на куски по `REPORT_CHUNK_SIZE` (по умолчанию 5000)
  через `chunkById` (память не растёт с числом товаров) и шлёт `Bus::batch`
  с `N = ceil(products / chunk_size)` заданий `GenerateReportChunkJob`.
- **Параллельная сборка**: задания чанков выполняются одновременно несколькими
  воркерами; каждый пишет свою часть `partNNNNN.csv` в `storage/app/reports/tmp/{rp_id}/`.
- **Финализация**: после успешного завершения батча Laravel вызывает
  `Bus::batch->finally(...)`, который запускает `FinalizeReportJob` —
  тот склеивает части через `stream_copy_to_stream`, дописывая BOM + заголовок,
  и удаляет `tmp/{rp_id}/`. Итог — один файл по спеке.
- **Стрим в файл**: каждый чанк пишет CSV через `fopen` + `fputcsv` построчно,
  не накапливая массив в памяти.
- **`DISTINCT ON (product_id) ... ORDER BY product_id, price ASC/DESC`** — два
  запроса на чанк (min и max), агрегация на стороне Postgres, не в PHP.
- **Покрытый индекс** `price(product_id, price_date)` уже создан миграцией.
- **Кратковременная HTTP-страница** не ждёт генерации — пользователь сразу видит
  строку в статусе «Запуск», а обновление — по факту завершения.
- В проде на 100M+ имеет смысл добавить партиционирование `price` по `price_date`
  (по месяцам), вынести файлы на S3 и масштабировать воркеры горизонтально
  (`docker compose up --scale worker=N`).

## Структура БД (миграции)

| Таблица           | Поля                                                              |
|-------------------|-------------------------------------------------------------------|
| `manufacturer`    | `manufacturer_id`, `manufacturer_name`                            |
| `product`         | `product_id`, `product_name`, `category_id`, `manufacturer_id`    |
| `price`           | `price_id`, `product_id`, `price`, `price_date`                   |
| `process_status`  | `ps_id`, `ps_name` (`Запуск`, `Завершен`, `Ошибка`)               |
| `report_process`  | `rp_id`, `rp_pid`, `rp_start_datetime`, `rp_exec_time`, `ps_id`, `rp_file_save_path` |
| `failed_jobs`     | стандартная таблица Laravel для упавших заданий                   |

## Быстрый старт

```bash
make up        # собрать образы и поднять postgres + redis + app + worker
                # app сам выполнит migrate + db:seed при первом запуске
make report                # поставить отчёт по категории 1 в очередь
make report CATEGORY=2     # категория 2
make report-empty          # категория 999 — нет товаров, ошибка
make work                  # хвост логов воркера
make queue-size            # сколько заданий ждёт в Redis
```

После `make report` сразу откройте `http://localhost:8000` — увидите строки
в статусе **Запуск**. Через секунду обновите — будут **Завершен** + ссылка
на скачивание CSV.

Если очередь заклинило (изменили код джобы — нужно сказать воркерам
перезапуститься):

```bash
make queue-restart
```

## Полезные команды Makefile

```
make up           # запустить
make down         # остановить
make migrate      # миграции
make fresh        # пересоздать БД и засидить
make seed         # засидить
make report           CATEGORY=N    # асинхронный отчёт через очередь
make report-empty                   # категория без товаров
make work                           # tail логов воркера
make queue-size                     # длина очереди в Redis
make queue-restart                  # сигнал воркерам restart
make queue-status                   # health всех контейнеров + queue depth + воркер-лог
make logs                           # tail логов app
make shell                          # bash внутри app-контейнера
make psql                           # psql в БД
make redis-cli                      # redis-cli в Redis
make clean                          # снести volumes (удалит данные)
```

## CLI команда отчёта

```bash
php artisan report:generate {category_id}
```

- На каждого производителя в категории создаётся отдельная запись в
  `report_process` (status = «Запуск») и публикуется задание в Redis-очередь
  `reports`.
- Воркер забирает задание и пишет файл вида
  `report_{manufacturer_id}_{category_id}_{ГГГГ-ММ-ДД_ЧЧ-ММ-СС}.csv`
  в `storage/app/reports/`.
- Колонки CSV: `manufacturer_name`, `product_name`, `price`, `price_date`
- На каждый товар — две строки: минимальная и максимальная цена за последние 7 дней
- Цена округляется до двух знаков после запятой
- Если в категории нет товаров — выводится сообщение об ошибке, команда
  возвращает FAILURE и в `report_process` создаётся запись со статусом
  `Ошибка` (без файла), чтобы попытка была видна на странице контроля.

После завершения воркер обновит запись на «Завершен» (с путём к файлу)
или «Ошибка» при фатальном сбое. Фатальные ошибки логируются через
`Log::error(...)` в `storage/logs/laravel.log`.

## HTTP-эндпоинт для отправки отчёта

`POST /reports` — то же, что и CLI, но из браузера или curl. Контроллер
тонкий: парсит форму, вызывает `App\Services\ReportDispatcher::dispatchForCategory()`,
делает redirect обратно на `/` с flash-сообщением.

```bash
# через curl (нужен CSRF-токен из формы):
curl -c /tmp/c.txt http://localhost:8000/ > /tmp/page.html
TOKEN=$(grep -oE 'name="_token" value="[^"]+"' /tmp/page.html | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')

curl -b /tmp/c.txt -c /tmp/c.txt -X POST http://localhost:8000/reports \
    -d "_token=${TOKEN}" -d "category_id=1"
# 302 → http://localhost:8000/
```

Ответы:
- 302 + flash-успех — на странице появятся новые строки в статусе «Запуск»
- 302 + flash-ошибка — для пустой категории (rp_process всё равно создаётся
  со статусом «Ошибка»; категория без товаров — это бизнес-ошибка, а не
  фатальная для логгера)
- 422 — невалидный `category_id` (отсутствует / не целое / <= 0)

## Health-check контейнеров

`php artisan health:check` проверяет, что текущий контейнер видит свои
зависимости (Postgres + Redis). Используется как docker-compose
`healthcheck:` на сервисах `app` и `worker` — `docker compose ps`
теперь показывает `(healthy)` для обоих, а не просто `running`.

```bash
make queue-status      # health всех контейнеров + queue depth + воркер-лог
```

Что НЕ ловит этот probe:
- Воркер тихо падает на каждой джобе (нужен `queue:monitor reports` по
  расписанию или Horizon).
- Backlog в Redis (запустите `make queue-size`, чтобы увидеть длину).

## Страница контроля

`GET /` — Blade-страница «Контроль выполнения процессов»:

- Сверху форма «Сформировать отчёт» — `<input type="number" name="category_id">`
  + кнопка submit. POSTит на `/reports` (CSRF-protected web-route), после
  redirect-а сверху показывается flash-сообщение (зелёное на успех, красное
  на ошибку). Никакого JS — стандартный server-side rendered form.
- Колонки таблицы: дата процесса, время выполнения (мс), PID, статус, файл
- Строки в статусе **Ошибка** подсвечены красным
- Для успешных процессов имя файла — гиперссылка на загрузку через
  `GET /processes/{rp_id}/download`
- Завершённые строки, чей файл удалили с диска, скрываются из листинга
  (контроллер фильтрует через `Storage::exists()`)
- Без JavaScript (страница статическая, обновление по F5)

## Проверка через curl

После `make up` приложение слушает на `http://localhost:8000`. Три публичных
маршрута:

| Маршрут                              | Назначение                                         |
|--------------------------------------|----------------------------------------------------|
| `GET /`                              | страница «Контроль выполнения процессов» (HTML)    |
| `POST /reports`                      | отправить отчёт (форма с CSRF; см. раздел выше)    |
| `GET /processes/{rp_id}/download`    | скачать CSV отчёта по записи из `report_process`   |

### Базовые запросы

```bash
# 1. Страница контроля (HTML)
curl -s http://localhost:8000/                       # тело
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://localhost:8000/

# 2. Только заголовки/код, без тела
curl -sI http://localhost:8000/                      # HEAD
curl -s -o /dev/null -w "%{http_code} %{content_type} %{size_download}\n" \
  http://localhost:8000/

# 3. Скачать отчёт по rp_id (тело + статус + заголовки)
curl -sS -D - -o /tmp/report.csv \
  -w "\nHTTP %{http_code} | %{size_download} bytes\n" \
  http://localhost:8000/processes/4/download

# 4. Скачать с именем, которое отдаёт сервер (Content-Disposition)
curl -OJ http://localhost:8000/processes/4/download
#  → создаст report_{mfr}_{cat}_{ГГГГ-ММ-ДД_ЧЧ-ММ-СС}.csv в текущей папке

# 5. Сначала глянуть метаданные, без выкачки
curl -sI http://localhost:8000/processes/4/download
#  HTTP/1.1 200 OK
#  Content-Type: text/csv; charset=utf-8
#  Content-Disposition: attachment; filename=report_1_1_2026-05-22_12-53-02.csv
#  Content-Length: 235
```

### Ожидаемые коды ответов

```bash
# 200 — нормальная загрузка завершённого отчёта
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/processes/4/download   # 200

# 404 — запись существует, но файла на диске нет (например, /storage cleaned)
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/processes/1/download   # 404

# 404 — строка в статусе «Ошибка», rp_file_save_path = NULL
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/processes/6/download   # 404

# 404 — нет такой записи
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/processes/9999/download  # 404

# 404 — нечисловой id отсекается роутом (whereNumber)
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/processes/abc/download   # 404

# 405 — единственный разрешённый метод GET
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8000/processes/4/download  # 405
```

### Полный сквозной сценарий

```bash
# 1. Поставить отчёт в очередь
make report CATEGORY=1

# 2. Сразу обновить страницу — увидеть строку в «Запуск»
curl -s http://localhost:8000/ | grep -E 'Запуск|Завершен|Ошибка'

# 3. Подождать секунду, повторить — теперь «Завершен» + ссылка на скачивание
sleep 2
curl -s http://localhost:8000/ | grep -oE '/processes/[0-9]+/download'

# 4. Скачать самый свежий завершённый отчёт
LATEST=$(curl -s http://localhost:8000/ \
  | grep -oE '/processes/[0-9]+/download' \
  | head -1)
curl -OJ "http://localhost:8000${LATEST}"
ls -la report_*.csv
```

## Pre-commit hook (Pint)

Версионированный pre-commit хук в `.githooks/pre-commit` запускает
`vendor/bin/pint --test` на staged `*.php` файлах и прерывает коммит при
style issues. Активация — один раз после клона:

```bash
make install-hooks      # git config core.hooksPath .githooks
```

Если приложение запущено в Docker, хук вызывает Pint через
`docker compose exec app`; если есть локальный `vendor/bin/pint` — через
него. Если ни того, ни другого — выводит понятную ошибку.

Откатить: `make uninstall-hooks`.
Прогнать вручную по всему репо: `make lint`.

## Тесты

```bash
make test       # создаст БД app_test при необходимости и прогонит PHPUnit
```

- Конфиг `phpunit.xml`, тесты в `tests/Feature/`.
- Используется отдельная БД `app_test` — dev-данные не затрагиваются.
- `RefreshDatabase` оборачивает каждый тест в транзакцию.
- Файлы CSV из тестов пишутся в `storage/app/reports_test/` (subdir
  переопределяется через env `REPORT_SUBDIR`) и удаляются в `tearDown()`.

Покрытие:

| Тест-кейс | Что проверяет |
|-----------|----------------|
| `GenerateReportJobTest` | BOM, заголовок, две строки на товар (min→max), фильтр >= today-7, пропуск товаров без цен в окне, округление до 2 знаков, статус Ошибка + лог при сбое записи, безопасный no-op при отсутствии `report_process` |
| `GenerateReportCommandTest` | Пустая категория → exit 1, нет строк, нет задач; обычная категория → строка в Запуск + Job в очереди (на каждого производителя); finally-callback wiring под Bus::fake для обеих ветвей (success / failure) |
| `ProcessControllerTest` | `GET /` рендерит строки, error-строки получают класс `status-error`, `processes.download` отдаёт CSV с правильным `Content-Disposition`, 404 на missing rp / missing file / null path |

## Горизонтальное масштабирование

```bash
docker compose up -d --scale worker=4
```

Redis-список — потокобезопасная FIFO-очередь: задания распределятся между
воркерами автоматически.

## Локально без Docker

```bash
composer install
cp .env.example .env       # отредактировать DB_HOST/REDIS_HOST под локальное окружение
php artisan migrate --seed
php artisan queue:work redis --queue=reports &   # в отдельном терминале
php artisan serve
```

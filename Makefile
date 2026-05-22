SHELL := /bin/bash

# Default category id used by `make report`. Override with: make report CATEGORY=2
CATEGORY ?= 1

DC := docker compose

.PHONY: help up down build rebuild install migrate fresh seed report report-sync report-empty \
        work worker-logs queue-size queue-restart logs shell psql redis-cli ps clean \
        test test-db

help:
	@echo "Targets:"
	@echo "  make up             - start the stack (postgres + redis + app + worker)"
	@echo "  make down           - stop the stack"
	@echo "  make build          - build the app image"
	@echo "  make rebuild        - rebuild without cache"
	@echo "  make install        - composer install inside the app container"
	@echo "  make migrate        - run database migrations"
	@echo "  make fresh          - drop + recreate schema and re-seed"
	@echo "  make seed           - run database seeders"
	@echo "  make report         - DISPATCH report job (CATEGORY=1 by default)"
	@echo "  make report-sync    - run report inline (no queue, useful for debugging)"
	@echo "  make report-empty   - try category 999 (no products -> error path)"
	@echo "  make work           - tail the worker (queue:work) logs"
	@echo "  make worker-logs    - same as 'make work'"
	@echo "  make queue-size     - show pending jobs in the reports queue"
	@echo "  make queue-restart  - signal workers to restart (after code changes)"
	@echo "  make logs           - tail app logs"
	@echo "  make shell          - bash into the app container"
	@echo "  make psql           - psql into the database"
	@echo "  make redis-cli      - redis-cli inside the redis container"
	@echo "  make ps             - container status"
	@echo "  make test           - run PHPUnit feature/unit suite (auto-creates app_test DB)"
	@echo "  make test-db        - just (re)create the app_test database"
	@echo "  make clean          - remove volumes (DELETES DATA)"

up:
	$(DC) up -d --build

down:
	$(DC) down

build:
	$(DC) build

rebuild:
	$(DC) build --no-cache

install:
	$(DC) run --rm app composer install

migrate:
	$(DC) exec app php artisan migrate --force

fresh:
	$(DC) exec app php artisan migrate:fresh --seed --force

seed:
	$(DC) exec app php artisan db:seed --force

report:
	$(DC) exec app php artisan report:generate $(CATEGORY)

report-sync:
	$(DC) exec app php artisan report:generate $(CATEGORY) --sync

report-empty:
	$(DC) exec app php artisan report:generate 999 || true

work:
	$(DC) logs -f worker

worker-logs: work

queue-size:
	$(DC) exec redis redis-cli LLEN "reportapp_database_queues:reports"

queue-restart:
	$(DC) exec app php artisan queue:restart

logs:
	$(DC) logs -f app

shell:
	$(DC) exec app bash

psql:
	$(DC) exec db psql -U app -d app

redis-cli:
	$(DC) exec redis redis-cli

ps:
	$(DC) ps

test-db:
	$(DC) exec -T db psql -U app -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='app_test'" | grep -q 1 \
		|| $(DC) exec -T db psql -U app -d postgres -c "CREATE DATABASE app_test OWNER app;"

test: test-db
	$(DC) exec -T -e APP_ENV=testing -e DB_DATABASE=app_test app vendor/bin/phpunit --colors=always

clean:
	$(DC) down -v

FROM php:8.2-cli

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libonig-dev \
        libzip-dev \
        ca-certificates \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8000

CMD ["sh", "-c", "\
    [ -d vendor ] || composer install --no-interaction --prefer-dist; \
    [ -f .env ] || cp .env.example .env; \
    mkdir -p storage/app/reports storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    until php -r 'new PDO(\"pgsql:host=\" . getenv(\"DB_HOST\") . \";port=\" . getenv(\"DB_PORT\") . \";dbname=\" . getenv(\"DB_DATABASE\"), getenv(\"DB_USERNAME\"), getenv(\"DB_PASSWORD\"));' 2>/dev/null; do \
        echo 'Waiting for postgres...'; sleep 2; \
    done; \
    php artisan migrate --force; \
    php artisan db:seed --force; \
    exec php artisan serve --host=0.0.0.0 --port=8000 \
"]

FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libsqlite3-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-interaction --prefer-dist --no-progress

RUN mkdir -p /app/storage/logs \
    && chmod +x /app/docker/app-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/app/docker/app-entrypoint.sh"]

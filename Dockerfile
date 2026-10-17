FROM php:8.5-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libonig-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip mbstring pcntl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

FROM php-base AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize && npm ci && npm run build

FROM php-base AS app
COPY --from=build /var/www/html /var/www/html
RUN rm -rf node_modules tests .github && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /var/www/html/public /var/www/html/public

# Production image: PHP app + queue worker + scheduler in one container (fits Render's free plan).

FROM php:8.4-cli AS base
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl pcntl zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev && php artisan package:discover

# Vite build (needs vendor/ for the Ziggy import).
FROM node:22-alpine AS assets
WORKDIR /app
COPY --from=base /app /app
ENV VITE_APP_NAME="Pixelforge Studio"
RUN npm ci && npm run build

FROM base
COPY --from=assets /app/public/build /app/public/build
ENV PHP_CLI_SERVER_WORKERS=4 LOG_CHANNEL=stderr
EXPOSE 10000
CMD ["sh", "docker/start.sh"]

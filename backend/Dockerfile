# syntax=docker/dockerfile:1.7
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache icu-libs libzip libpq \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl zip pdo_pgsql pcntl opcache bcmath \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build-deps

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /var/www/html

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && rm /usr/bin/composer \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

# syntax=docker/dockerfile:1

FROM composer:lts as deps

WORKDIR /app

# Install PHP extensions in the deps stage
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && docker-php-ext-install bcmath pcntl

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --no-interaction

FROM php:8.2-fpm as final

# Install PHP extensions in the final stage
RUN pecl install apcu \
    && docker-php-ext-enable apcu \
    && docker-php-ext-install bcmath pcntl

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=deps /app/vendor/ /var/www/html/vendor
COPY ./ /var/www/html
RUN touch /var/www/html/.env
COPY mtla_members.json /var/www/
RUN mkdir -p /var/www/bsn.mtla.me/app/
COPY bsn.json /var/www/bsn.mtla.me/app/bsn.json

USER www-data

# Expose port 9000
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]

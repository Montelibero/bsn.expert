FROM node:22-alpine AS fa-assets

WORKDIR /build

RUN apk add --no-cache \
    g++ \
    make \
    python3

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci

COPY fontawesome.json ./
COPY tools/fontawesome ./tools/fontawesome
RUN npm run fa_build

FROM php:8.5-fpm-alpine AS base

WORKDIR /var/www/html

RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    libmemcached-dev \
    openssl-dev \
    icu-dev \
    cyrus-sasl-dev \
    zlib-dev \
    curl-dev \
    oniguruma-dev \
    autoconf \
    g++ \
    make \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        curl \
        gd \
        intl \
        pcntl \
    && pecl install apcu \
    && pecl install memcached \
    && pecl install mongodb \
    && docker-php-ext-enable apcu memcached mongodb

COPY app/custom-php.ini /usr/local/etc/php/conf.d/99-custom.ini

COPY app/php-fpm-logs.conf /usr/local/etc/php-fpm.d/zz-logs.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Development
FROM base AS dev
COPY app/php-dev.ini /usr/local/etc/php/conf.d/zz-env.ini
CMD ["sh", "-c", "sh /var/www/html/init.sh && composer install --no-dev --optimize-autoloader && php-fpm"]

# Production
FROM base AS prod
COPY app/php-prod.ini /usr/local/etc/php/conf.d/zz-env.ini

COPY app/composer.json app/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY app/ .
COPY --from=fa-assets /build/app/twig/fontawesome_link.twig twig/fontawesome_link.twig

RUN composer dump-autoload --apcu
RUN chmod +x /var/www/html/init.sh /var/www/html/cron/start-cron.sh

CMD ["sh", "-c", "sh /var/www/html/init.sh && php-fpm -F"]

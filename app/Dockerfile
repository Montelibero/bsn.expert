FROM php:8.2-fpm-alpine AS base

WORKDIR /var/www/html

RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    libmemcached-dev \
    zlib-dev \
    curl-dev \
    oniguruma-dev \
    autoconf \
    g++ \
    make \
    mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        bcmath \
        curl \
        gd \
        pcntl \
    && pecl install apcu \
    && pecl install memcached \
    && docker-php-ext-enable apcu memcached

COPY ./custom-php.ini /usr/local/etc/php/conf.d/99-custom.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Development
FROM base AS dev
CMD ["sh", "-c", "sh /var/www/html/init.sh && composer install --no-dev --optimize-autoloader && php-fpm"]

# Production
FROM base AS prod

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload -o

CMD ["sh", "-c", "sh /var/www/html/init.sh && php-fpm -F"]

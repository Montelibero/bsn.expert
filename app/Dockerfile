FROM php:8.2-fpm-alpine

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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        bcmath \
        curl \
        gd \
    && pecl install apcu \
    && pecl install memcached \
    && docker-php-ext-enable apcu memcached

WORKDIR /var/www/html

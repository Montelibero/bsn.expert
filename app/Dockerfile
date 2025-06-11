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

WORKDIR /var/www/html

COPY . .

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

CMD ["sh", "init.sh"]

FROM node:24-alpine AS fa-assets

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

FROM dunglas/frankenphp:1-php8.5-bookworm AS base

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends cron unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions \
        apcu \
        bcmath \
        curl \
        gd \
        intl \
        memcached \
        mongodb \
        pcntl \
        zip

COPY app/custom-php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY app/Caddyfile /etc/caddy/Caddyfile

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Development with FrankenPHP worker mode
FROM base AS dev
ENV APP_ENV=dev \
    FRANKENPHP_WORKER_NUM=1 \
    MAX_REQUESTS=200
COPY app/php-dev.ini /usr/local/etc/php/conf.d/zz-env.ini
CMD ["sh", "-c", "composer install --no-dev --optimize-autoloader && sh /var/www/html/init.sh && exec frankenphp run --config /etc/caddy/Caddyfile"]

# Production with FrankenPHP worker mode
FROM base AS prod
ENV APP_ENV=prod \
    FRANKENPHP_WORKER_NUM=8 \
    MAX_REQUESTS=1000
COPY app/php-prod.ini /usr/local/etc/php/conf.d/zz-env.ini

COPY app/composer.json app/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader --apcu-autoloader

COPY --link --from=fa-assets /build/app/fontawesome ./fontawesome
COPY --link --from=fa-assets /build/app/twig/fontawesome_link.twig twig/fontawesome_link.twig
COPY --link app/ .

RUN composer test

RUN find /var/www/html -type f \( \
        -name '*.css' -o \
        -name '*.html' -o \
        -name '*.js' -o \
        -name '*.json' -o \
        -name '*.mjs' -o \
        -name '*.svg' -o \
        -name '*.toml' -o \
        -name '*.ttf' -o \
        -name '*.txt' -o \
        -name '*.xml' \
    \) -exec sh -c 'for file do gzip -kf -9 "$file" && touch -r "$file" "$file.gz"; done' sh {} +

CMD ["sh", "-c", "sh /var/www/html/init.sh && exec frankenphp run --config /etc/caddy/Caddyfile"]

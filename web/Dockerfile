FROM nginx:stable-alpine as base

WORKDIR /var/www/html

# Development
FROM base AS dev

# Production
FROM base AS prod
COPY web/default.conf /etc/nginx/conf.d/default.conf
COPY app/ .

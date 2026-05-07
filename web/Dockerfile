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

FROM nginx:stable-alpine AS base

WORKDIR /var/www/html

# Development
FROM base AS dev

# Production
FROM base AS prod
COPY web/default.conf /etc/nginx/conf.d/default.conf
COPY app/ .
COPY --from=fa-assets /build/app/fontawesome ./fontawesome
COPY --from=fa-assets /build/app/twig/fontawesome_link.twig ./twig/fontawesome_link.twig

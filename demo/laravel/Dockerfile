FROM composer:2 AS deps
WORKDIR /build

# Copy SDK packages for path repository resolution
COPY packages/ /sdk-packages/

# Copy demo app composer files (lock file excluded — path repos differ in Docker)
COPY demo/laravel/composer.json ./

# Rewrite path repositories to /sdk-packages/ for Docker build context
RUN sed -i 's|../../packages/|/sdk-packages/|g' composer.json

# COMPOSER_MIRROR_PATH_REPOS=1 forces path repos to be copied (not symlinked),
# which is required for multi-stage Docker builds
ENV COMPOSER_MIRROR_PATH_REPOS=1
RUN composer update --no-dev --no-scripts --prefer-dist --no-interaction

FROM php:8.4-cli

# Install PHP extensions required by Laravel
RUN apt-get update && apt-get install -y --no-install-recommends \
        libxml2-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install opcache mbstring xml ctype curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --exclude=vendor --exclude=.env demo/laravel/ .
COPY --from=deps /build/vendor ./vendor

# Create .env from example, generate app key, set up storage directories
RUN cp .env.example .env \
    && php artisan key:generate --no-interaction \
    && mkdir -p storage/framework/cache/convert storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]

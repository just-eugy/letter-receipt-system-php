FROM php:8.4-fpm-alpine

ARG UID=1000
ARG GID=1000

# Install mlocati/php-extension-installer for pre-compiled extensions
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apk add --no-cache \
    git \
    unzip \
    && install-php-extensions \
    pdo_mysql \
    zip \
    gd \
    bcmath \
    && apk del git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN addgroup -g ${GID} -S laravel \
    && adduser -u ${UID} -S laravel -G laravel

WORKDIR /var/www/html

COPY --chown=laravel:laravel composer.json composer.lock* ./

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts \
    && rm -rf /home/laravel/.composer/cache

COPY --chown=laravel:laravel . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

RUN mkdir -p storage/framework/{cache,sessions,views,testing} \
    bootstrap/cache \
    && chown -R laravel:laravel storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER laravel

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php artisan --version || exit 1

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
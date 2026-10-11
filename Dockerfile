FROM php:8.4-fpm-alpine3.20

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
    git \
    unzip \
    supervisor \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
    pdo_mysql \
    zip \
    gd \
    mbstring \
    bcmath \
    opcache \
    pcntl \
    && apk del $PHPIZE_DEPS

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

# Copy supervisor config
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create log directory
RUN mkdir -p /var/log && chown -R laravel:laravel /var/log

EXPOSE 8000 8083

HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php artisan --version || exit 1

USER laravel

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
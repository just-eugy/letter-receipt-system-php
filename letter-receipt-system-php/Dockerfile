FROM php:8.4-fpm-alpine3.20

ARG UID=1000
ARG GID=1000

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    git \
    unzip \
    supervisor \
    nginx \
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

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create laravel user
RUN addgroup -g ${GID} -S laravel \
    && adduser -u ${UID} -S laravel -G laravel

WORKDIR /var/www/html

# Copy composer files
COPY --chown=laravel:laravel composer.json composer.lock* ./

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts \
    && rm -rf /home/laravel/.composer/cache

# Copy application code
COPY --chown=laravel:laravel . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# Storage & cache permissions
RUN mkdir -p storage/framework/{cache,sessions,views,testing} \
    bootstrap/cache \
    && chown -R laravel:laravel storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Copy supervisor config
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy wait-for-db script
COPY docker/wait-for-db.sh /var/www/html/docker/wait-for-db.sh
RUN chmod +x /var/www/html/docker/wait-for-db.sh

# Copy nginx config
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/conf.d /etc/nginx/conf.d

# Copy React frontend build
COPY frontend-dist /usr/share/nginx/html

# Create log directories
RUN mkdir -p /var/log /var/cache/nginx /var/log/nginx /run/nginx \
    && chown -R laravel:laravel /var/log /var/cache/nginx /var/log/nginx /run/nginx

EXPOSE 8000 8083

HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://localhost:8000/up || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
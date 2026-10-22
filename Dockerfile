FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor ./vendor
COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json tailwind.config.js postcss.config.js ./
RUN npm run build

FROM php:8.3-fpm-alpine
RUN apk add --no-cache nginx envsubst postgresql-dev libzip-dev oniguruma-dev clamav clamav-libunrar \
    && docker-php-ext-install pdo_pgsql mbstring zip
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/start.sh /usr/local/bin/kalinga-start
COPY docker/schedule.sh /usr/local/bin/kalinga-schedule
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && rm -f public/hot \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/kalinga-start /usr/local/bin/kalinga-schedule \
    && php artisan package:discover --ansi
EXPOSE 8000
CMD ["/usr/local/bin/kalinga-start"]

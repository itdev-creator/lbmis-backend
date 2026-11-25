# One Dockerfile, two images:
#   --target app  -> PHP-FPM running Laravel (also used for queue and scheduler)
#   --target web  -> nginx serving public/ and passing PHP to the app container

# ---- PHP dependencies ----
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# ---- Front-end assets ----
FROM --platform=$BUILDPLATFORM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# app.css imports Flux's stylesheet from vendor/, so bring the PHP packages in
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- Application (PHP-FPM) ----
FROM php:8.4-fpm-alpine AS app
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis intl zip gd bcmath exif pcntl opcache
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'upload_max_filesize=50M\npost_max_size=50M\nmemory_limit=256M\nexpose_php=Off\nopcache.validate_timestamps=0\n' > "$PHP_INI_DIR/conf.d/zz-lbmis.ini"
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
USER www-data
RUN php artisan package:discover --ansi
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---- Web server (nginx) ----
FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

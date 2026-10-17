# Image de production de la plateforme GFP (Laravel + Apache), utilisée par Render.
FROM php:8.4-apache

# Extensions PHP : PostgreSQL (Supabase), MySQL/MariaDB (usage local), et celles de Laravel.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pdo_mysql intl zip bcmath opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache sert le dossier public/ et écoute sur le port fourni par Render (variable PORT).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=10000 \
    COMPOSER_ALLOW_SUPERUSER=1
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite headers

WORKDIR /var/www/html

# Dépendances PHP d'abord (mises en cache tant que composer.lock ne change pas).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
       storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/start.sh

CMD ["docker/start.sh"]

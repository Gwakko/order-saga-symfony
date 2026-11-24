FROM php:8.5-fpm

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.9.0/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql intl opcache redis zip pcntl sockets

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

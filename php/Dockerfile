FROM php:8.5-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
        libicu-dev libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && for ext in pdo_mysql mysqli gd zip intl bcmath exif; do \
         docker-php-ext-install -j"$(nproc)" "$ext"; \
       done \
    && rm -rf /tmp/pear

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

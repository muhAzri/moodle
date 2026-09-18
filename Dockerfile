FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libxslt1-dev \
        libonig-dev \
        libsodium-dev \
        libcurl4-openssl-dev \
        libpq-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        pgsql \
        pdo_pgsql \
        soap \
        xsl \
        zip \
        exif \
        sodium \
        opcache \
        mbstring \
        curl \
    && a2enmod rewrite

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/moodle.ini /usr/local/etc/php/conf.d/moodle.ini

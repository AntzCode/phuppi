# Dockerfile
#
# Dockerfile for building the PHP Apache container for the Phuppi application.
#
# @package Phuppi
# @author Anthony Gallon
# @copyright AntzCode Ltd
# @license GPLv3
# @link https://github.com/AntzCode/phuppi/
# @since 2.0.0

FROM php:8.2-apache

ARG UID=1000
ARG GID=1000

RUN usermod -u $UID www-data && groupmod -g $GID www-data

RUN chown -R www-data:www-data /var/www/html

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    sqlite3 \
    libzip-dev \
    wget \
    nano \
    htop \
    ffmpeg \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmagickwand-dev \
    libpng-dev \
    libwebp-dev \
    pkg-config \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_sqlite zip gd pcntl \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHPStan as a dev tool
RUN wget https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar -O /usr/local/bin/phpstan && chmod +x /usr/local/bin/phpstan

RUN mkdir -p /var/www/data/logs

COPY docker/php/000-default.conf /etc/apache2/sites-available/000-default.conf
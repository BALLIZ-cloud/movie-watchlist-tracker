FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install \
        curl \
        mbstring \
        pdo_mysql \
    && a2enmod rewrite \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
COPY . /var/www/html

RUN mkdir -p /var/www/html/public/uploads \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

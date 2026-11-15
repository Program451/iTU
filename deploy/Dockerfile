FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j1 pdo_mysql mbstring gd \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/app
COPY public/ public/
COPY database/ database/
COPY tools/ tools/
COPY tests/ tests/
COPY deploy/initialize.php deploy/initialize.php
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/aulieata.ini
RUN mkdir -p /var/www/app/aulieata-media && chown www-data:www-data /var/www/app/aulieata-media \
    && a2enmod headers setenvif \
    && sed -i 's/StartServers.*/StartServers 1/; s/MinSpareServers.*/MinSpareServers 1/; s/MaxSpareServers.*/MaxSpareServers 2/; s/MaxRequestWorkers.*/MaxRequestWorkers 4/; s/MaxConnectionsPerChild.*/MaxConnectionsPerChild 200/' /etc/apache2/mods-available/mpm_prefork.conf

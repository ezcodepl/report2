FROM php:8.2-fpm

# Instalacja wymaganych rozszerzeń (libzip do obsługi ZIP, pdo_mysql do bazy)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && docker-php-ext-install zip pdo_mysql

# Bezpieczne zapisanie limitów bezpośrednio w konfiguracji PHP kontenera
RUN echo "upload_max_filesize = 120M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 120M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

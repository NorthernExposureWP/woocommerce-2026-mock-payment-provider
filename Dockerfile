FROM php:8.4-apache

WORKDIR /var/www/html

COPY public/ /var/www/html/

COPY storage/ /var/www/storage/

RUN chown -R www-data:www-data /var/www/storage \
    && chmod -R 750 /var/www/storage

RUN a2enmod rewrite

EXPOSE 80
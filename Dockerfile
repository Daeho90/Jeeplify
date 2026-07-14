FROM php:8.2-apache

# Install extensions
RUN apt-get update && apt-get install -y libssl-dev && \
    docker-php-ext-install pdo pdo_mysql mysqli && \
    docker-php-ext-enable pdo_mysql && \
    pecl install openssl 2>/dev/null || true && \
    docker-php-ext-install -j$(nproc) && \
    a2enmod rewrite

# Enable curl and openssl
RUN apt-get install -y libcurl4-openssl-dev && \
    docker-php-ext-install curl

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

ENV PORT=10000
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
EXPOSE 10000

CMD ["apache2-foreground"]
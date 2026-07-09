FROM php:8.2-apache

# Install PDO MySQL extension (needed for db.php)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache mod_rewrite (useful for clean URLs, harmless if unused)
RUN a2enmod rewrite

# Copy project files into Apache's web root
COPY . /var/www/html/

# Make sure Apache can read everything
RUN chown -R www-data:www-data /var/www/html

# Render sets $PORT dynamically — default Apache to listen there
ENV PORT=10000
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
EXPOSE 10000

CMD ["apache2-foreground"]
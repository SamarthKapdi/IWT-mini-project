FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Copy app
COPY MINI_PROJECT_IWT.php /var/www/html/index.php

# Expose port 80 (Render maps $PORT)
EXPOSE 80

# Apache runs as the default CMD

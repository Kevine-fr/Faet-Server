FROM php:8.2-fpm-alpine

# Installer dépendances système minimales
RUN apk --no-cache add \
    nginx \
    curl \
    git \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    postgresql-dev \
    bash

# Installer extensions PHP nécessaires
RUN docker-php-ext-install pdo pdo_pgsql pgsql opcache

# Configurer PHP-FPM (optimisé pour 1 CPU / 2GB RAM)
RUN sed -i 's/;pm.max_children = .*/pm.max_children = 6/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;pm.start_servers = .*/pm.start_servers = 3/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;pm.min_spare_servers = .*/pm.min_spare_servers = 2/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;pm.max_spare_servers = .*/pm.max_spare_servers = 3/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;pm.max_requests = .*/pm.max_requests = 300/' /usr/local/etc/php-fpm.d/www.conf

# Ajouter configuration OPcache
#COPY ./php.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www

# Copier le code
COPY . .

# Permissions Laravel
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Installer Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Installer dépendances Laravel en mode production
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copier config Nginx
COPY ./nginx.conf /etc/nginx/http.d/default.conf

# Copier l’entrypoint
COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000

CMD ["/entrypoint.sh"]

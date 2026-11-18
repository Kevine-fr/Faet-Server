#!/bin/sh

# Mise en cache Laravel pour prod
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Démarrer Nginx + PHP-FPM
nginx &
php-fpm
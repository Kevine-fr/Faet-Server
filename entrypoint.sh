#!/bin/sh

# Mise en cache Laravel pour prod
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Démarrer Nginx + PHP-FPM. `exec` : PHP-FPM devient le processus 1 et reçoit
# le SIGTERM de `docker stop` (sinon le shell l'ignorait et Docker tuait le
# conteneur au bout de 10 s, requêtes en cours comprises).
nginx
exec php-fpm
# Faet

API Laravel d'une place de marché multi-vendeurs : boutiques de vendeurs, catalogue et variantes de produits, promotions et codes promo, panier, commandes, paiements et factures, avec connexion Google et JWT.

## Fonctionnalités

- **Clients** : inscription avec code de vérification par e-mail, connexion (mot de passe ou Google), profil, localisation, favoris.
- **Boutique** : produits, nouveautés, promotions, populaires, recherche et suggestions, produits similaires, pages vendeurs.
- **Commandes** : panier, options de livraison, codes promo, paiement (PayPal), factures.
- **Vendeurs** : abonnement, documents, catalogue et statistiques de visite.
- **Administration** : tableaux de bord, audiences, politiques, notifications.

## Pile technique

PHP 8.2, Laravel 12, PostgreSQL, JWT (RS256), nginx + PHP-FPM dans un seul conteneur Docker.

## Démarrer en local

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve
```

## Faet sur le VPS

- **Conteneur** : `faet-api` (nginx + PHP-FPM, port 8000), réseau Docker `web`.
- **Santé** : `GET /up` (aussi le `HEALTHCHECK` de l'image).
- **Métriques** : `GET /metrics` — `http_requests_total` et
  `http_request_duration_seconds` {method, route, status_code}, lues par le
  Prometheus du dépôt Infrastructure (job `faet-api`).
- **Sauvegarde** : `./scripts/backup.sh` (base PostgreSQL + `storage/app`, un
  seul fichier, chemin en dernière ligne) ; `./scripts/restore.sh <fichier>`.
  L'envoi automatique sur MinIO est fait par le dépôt Infrastructure.
- **Tests** : `vendor/bin/phpunit` et `bash tests/scripts/sauvegarde.test.sh`.
- **Déploiement** : `docker compose -f docker-compose.prod.yml up -d --build` (conteneur `faet-api`, `.env` lu au lancement).
- Conventions de travail : `CLAUDE.md`.

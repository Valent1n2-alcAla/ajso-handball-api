# AJSO Handball — API

API Symfony 7.4 + API Platform 4 du site de l'AJS Ouistreham Handball.

## Ressources

| Ressource | Endpoint | Lecture | Écriture |
|---|---|---|---|
| Équipes (club + adversaires, `estAjso`) | `/api/equipes` | publique | admin |
| Matchs | `/api/rencontres` | publique | admin |
| Actualités | `/api/actualites` | publique | admin |
| Créneaux d'entraînement | `/api/creneaux` | publique | admin |
| Gymnases, Partenaires, Tarifs, Club | `/api/gymnases`, `/api/partenaires`, `/api/tarifs`, `/api/clubs` | publique | admin |
| Catégories, Championnats, Tranches | `/api/categories`, `/api/championnats`, `/api/tranches` | publique | admin |
| Messages de contact | `/api/message_contacts` | admin | `POST` public |

- **Matchs** : `equipe` = équipe AJSO, `equipeAdverse` = adversaire, `domicile` = l'AJSO reçoit, `scoreDom` = score de l'équipe qui reçoit.
- Filtres : `?equipe=`, `?date[after]=`, `?order[date]=`, `?estAjso=true`, `?lu=false`, `?itemsPerPage=` (max 200).
- Documentation interactive : <http://localhost:8000/api>.

## Authentification

JWT (LexikJWTAuthenticationBundle). `POST /api/login` avec `{"email", "password"}` → `{"token"}`,
puis en-tête `Authorization: Bearer <token>`. Connexions limitées à 5 essais / 15 min.

## Installation

```bash
composer install
php bin/console lexik:jwt:generate-keypair          # clés dans config/jwt (non versionnées)
# Base PostgreSQL : adapter DATABASE_URL dans .env.local
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load               # données de départ (optionnel)
php bin/console app:create-admin president@exemple.fr   # compte admin (mot de passe demandé)
symfony serve   # ou : php -S 127.0.0.1:8000 -t public
```

## Production

Dans `.env.local` (non versionné) : `APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL`, `JWT_PASSPHRASE`
(nouvelle valeur, puis régénérer les clés) et `CORS_ALLOW_ORIGIN` (ex. `'^https://ajso-handball\.fr$'`).

## Données de départ

Les fixtures reprennent les informations publiques du club (président, gymnases, équipes 2025-2026, label).
Matchs, créneaux, tarifs et partenaires sont **des exemples** à remplacer depuis l'espace admin du site.

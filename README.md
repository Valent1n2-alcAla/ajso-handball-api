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
| Classements | `/api/classements` | publique | admin |
| Galerie : albums, photos | `/api/albums`, `/api/photos` | publique | admin |
| Agenda (événements) | `/api/evenements` | publique | admin |
| Boutique (produits) | `/api/produits` | publique | admin |
| Envoi d'images | `POST /api/medias` (multipart, champ `fichier`) | — | admin |
| Messages de contact | `/api/message_contacts` | admin | `POST` public |

- **Matchs** : `dateConfirmee=false` = seul le week-end est connu (`date` = samedi), `heure` facultative, `journee` libre.
- `equipe` = équipe AJSO, `equipeAdverse` = adversaire, `domicile` = l'AJSO reçoit, `scoreDom` = score de l'équipe qui reçoit.
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

## Importer un calendrier

```bash
php bin/console app:import-rencontres calendrier.csv --dry-run   # vérification
php bin/console app:import-rencontres calendrier.csv
```

Modèle : [`docs/modele-calendrier.csv`](docs/modele-calendrier.csv) (séparateur `;`).
`equipe` = nom exact d'une équipe AJSO ; adversaires créés automatiquement ;
`date` = `AAAA-MM-JJ`, ou `WE AAAA-MM-JJ` (samedi) si seul le week-end est connu → affiché « Week-end du … » ;
`heure` vide = « Horaire à venir ».

## Production

Dans `.env.local` (non versionné) : `APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL`, `JWT_PASSPHRASE`
(nouvelle valeur, puis régénérer les clés) et `CORS_ALLOW_ORIGIN` (ex. `'^https://ajso-handball\.fr$'`).

## Données de départ

Les fixtures reprennent les informations publiques du club (président, gymnases, équipes 2025-2026, label).
Matchs, créneaux, tarifs et partenaires sont **des exemples** à remplacer depuis l'espace admin du site.

## Images envoyées

`POST /api/medias` range les fichiers dans `public/uploads/AAAA/MM/` (JPG, PNG, WebP, GIF, 8 Mo max) et
renvoie leur URL. Le site réduit les photos à 2000 px avant l'envoi. En production, si l'API est derrière
un autre nom de domaine que celui vu par le serveur, définir `UPLOADS_BASE_URL` (ex. `https://api.ajso-handball.fr`).
Vérifier aussi `upload_max_filesize` / `post_max_size` dans `php.ini` (8M / 10M ; `public/.user.ini` le fait pour PHP-FPM).

## Import automatique FFHandball

Récupère matchs, horaires, salles, scores et classement sur ffhandball.fr (données lues dans le HTML des pages).

1. Admin du site → **Championnats** : coller l'adresse de la page de la poule
   (`https://www.ffhandball.fr/competitions/…/poule-123456/`).
2. Admin → **Équipes** : saisir le **nom exact** de l'équipe sur ffhandball.fr (ex. `AJS OUISTREHAM HANDBALL 2`).
3. Lancer l'import : bouton **Import FFHandball** de l'admin, ou en ligne de commande :

```bash
php bin/console app:ffhb:import              # toutes les équipes configurées
php bin/console app:ffhb:import --equipe=12  # une seule
```

Les matchs importés sont reconnus par leur identifiant fédéral (pas de doublon) ; les adversaires sont créés
au besoin ; les journées closes ne sont pas relues ; une pause d'1 s sépare deux pages (`FFHB_PAUSE`).
Seules les adresses de `FFHB_HOSTS` (par défaut `www.ffhandball.fr`) sont acceptées.

**Planifier** l'import deux fois par jour :

- Linux (crontab) : `0 7,23 * * * cd /chemin/ajso-handball-api && php bin/console app:ffhb:import`
- Windows (PowerShell admin) :
  ```powershell
  $a = New-ScheduledTaskAction -Execute "php" -Argument "bin/console app:ffhb:import" -WorkingDirectory "D:\AjsoHandball\ajso-handball-api"
  Register-ScheduledTask -TaskName "AJSO import FFHB" -Action $a -Trigger @((New-ScheduledTaskTrigger -Daily -At 7am), (New-ScheduledTaskTrigger -Daily -At 11pm))
  ```

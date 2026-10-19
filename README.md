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
| Effectifs (joueurs) | `/api/joueurs` (public) · `/api/admin/joueurs` (nom complet) | publique | admin |
| Classements | `/api/classements` | publique | admin |
| Galerie : albums, photos | `/api/albums`, `/api/photos` | publique | admin |
| Agenda (événements) | `/api/evenements` | publique | admin |
| Boutique (produits) | `/api/produits` | publique | admin |
| Convocations aux matchs | `/api/convocations` | publique (publiées seulement) | admin |
| Permanences des bénévoles | `/api/permanences` | publique | admin |
| Documents à télécharger | `/api/documents` | publique | admin |
| Envoi d'images et de PDF | `POST /api/medias` (multipart, champ `fichier`) | — | admin |
| Messages de contact | `/api/message_contacts` | admin | `POST` public |
| Préinscriptions | `/api/preinscriptions` | admin | `POST` public |

- **Matchs** : `dateConfirmee=false` = seul le week-end est connu (`date` = samedi), `heure` facultative, `journee` libre.
- `equipe` = équipe AJSO, `equipeAdverse` = adversaire, `domicile` = l'AJSO reçoit, `scoreDom` = score de l'équipe qui reçoit.
- Filtres : `?equipe=`, `?date[after]=`, `?order[date]=`, `?estAjso=true`, `?lu=false`, `?itemsPerPage=` (max 200).
- Saison : `/api/rencontres?date[after]=2026-08-01&date[before]=2027-07-31`.
- **Convocations** : `rencontre`, `rendezVous` (`AAAA-MM-JJTHH:MM`), `lieuRendezVous`, `joueurs` (publiés par
  `nomAffiche`), `message`, `publiee` ; un brouillon (`publiee=false`) n'est visible que par l'admin.
  Filtres `?rencontre.equipe=`, `?rencontre=`, `?rendezVous[after]=`, `?rencontre.date[after]=`.
- **Permanences** : `rencontre`, `role` (`arbitrage`, `table`, `buvette`, `accueil`, `autre`), `benevole`
  (nom affiché, prénom + initiale conseillés ; vide = à pourvoir), `besoin` (nombre de personnes).
  Filtres `?rencontre=`, `?rencontre.equipe=`, `?role=`, `?rencontre.date[after]=`.
- **Préinscriptions** : envoi public limité à 5 par heure et par adresse IP ; alerte e-mail à `ADMIN_EMAIL`.
- **Documents** : `titre`, `url` (fichier envoyé par `/api/medias`), `categorie` (`inscription`, `reglement`,
  `assemblee`, `autre`), `ordre` ; filtre `?categorie=`.
- **HelloAsso** : `helloassoUrl` (https) sur les tarifs et le club.
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

## Mise en production

1. **`.env.local`** (non versionné, jamais commité) :

   ```dotenv
   APP_ENV=prod
   APP_SECRET=<64 caractères aléatoires>          # php -r "echo bin2hex(random_bytes(32));"
   DATABASE_URL="postgresql://ajso:<motdepasse>@127.0.0.1:5432/ajso_handball?serverVersion=18&charset=utf8"
   JWT_PASSPHRASE=<nouvelle phrase secrète>       # celle de .env ne sert qu'au développement
   CORS_ALLOW_ORIGIN='^https://(www\.)?ajso-handball\.fr$'
   TRUSTED_HOSTS='^api\.ajso-handball\.fr$'
   TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR          # seulement derrière un reverse proxy
   UPLOADS_BASE_URL=https://api.ajso-handball.fr  # si le nom vu par PHP diffère du nom public
   MAILER_DSN=smtp://utilisateur:motdepasse@smtp.exemple.fr:587
   ADMIN_EMAIL=bureau@ajso-handball.fr            # plusieurs adresses séparées par des virgules
   MAILER_FROM=site@ajso-handball.fr
   PG_DUMP_PATH='C:\Program Files\PostgreSQL\18\bin\pg_dump.exe'
   ```

2. **Clés JWT** propres à la production (avec la nouvelle `JWT_PASSPHRASE`) :
   `php bin/console lexik:jwt:generate-keypair --overwrite`
3. **Installation** :

   ```bash
   composer install --no-dev --optimize-autoloader
   composer dump-env prod                       # compile .env + .env.local dans .env.local.php
   php bin/console doctrine:migrations:migrate -n
   php bin/console cache:clear && php bin/console cache:warmup
   ```

   Après toute modification de `.env.local`, relancer `composer dump-env prod` puis `cache:clear`.
4. **Tâches planifiées** (import FFHB, sauvegardes, purge RGPD) : voir ci-dessous.
5. **Sauvegardes** : vérifier qu'une première `php bin/console app:sauvegarde` fonctionne, et copier
   régulièrement `var/sauvegardes/` sur un autre support (disque externe, cloud).
6. **Journaux** : `var/log/prod-AAAA-MM-JJ.log` (14 jours conservés) : erreurs de l'application,
   résumés de l'import FFHB, échecs d'envoi d'e-mails, sauvegardes.

## Tâches planifiées

**Windows** : PowerShell **en administrateur**, dans le dossier du projet :

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\taches-planifiees.ps1
# php.exe hors du PATH :  ... -File .\scripts\taches-planifiees.ps1 -Php "C:\php\php.exe"
# pour tout retirer :     ... -File .\scripts\taches-planifiees.ps1 -Supprimer
```

Le script crée, dans le dossier « AJSO Handball » du Planificateur de tâches (exécution même sans session ouverte) :

| Tâche | Commande | Quand |
|---|---|---|
| Import FFHB | `app:ffhb:import` | toutes les 2 h de 8 h à 22 h |
| Relecture feuilles FFHB | `app:ffhb:import --relire-feuilles` | lundi 4 h |
| Sauvegarde | `app:sauvegarde` | chaque nuit 3 h |
| Purge messages | `app:messages:purger` | toutes les 4 semaines, dimanche 5 h |

**Linux** (`crontab -e`) :

```cron
0 8-22/2 * * *  cd /chemin/ajso-handball-api && php bin/console app:ffhb:import --no-interaction
0 4 * * 1       cd /chemin/ajso-handball-api && php bin/console app:ffhb:import --relire-feuilles --no-interaction
0 3 * * *       cd /chemin/ajso-handball-api && php bin/console app:sauvegarde --no-interaction
0 5 1 * *       cd /chemin/ajso-handball-api && php bin/console app:messages:purger --no-interaction
```

## Notifications par e-mail

Avec `ADMIN_EMAIL` (et `MAILER_DSN`) renseignés, un e-mail texte est envoyé pour chaque nouveau message de
contact et chaque préinscription (« Répondre » écrit directement à l'expéditeur), ainsi qu'un résumé quand
l'import FFHB rencontre des erreurs ou qu'une sauvegarde échoue. `ADMIN_EMAIL` vide = aucun e-mail.
Un échec d'envoi est journalisé, sans jamais faire échouer l'envoi du formulaire.

## Sauvegardes

```bash
php bin/console app:sauvegarde                 # base + public/uploads, 14 dernières gardées
php bin/console app:sauvegarde --garder=30 --sans-fichiers
```

Fichiers : `var/sauvegardes/AAAA-MM-JJ_HHMM.sql.gz` (pg_dump) et `AAAA-MM-JJ_HHMM_uploads.zip`.
`pg_dump` est cherché dans le PATH puis dans `C:\Program Files\PostgreSQL\*\bin` ; sinon renseigner `PG_DUMP_PATH`.
Restauration (base vide) : décompresser (7-Zip, `gunzip`), puis `psql -U postgres -d ajso_handball -f AAAA-MM-JJ_HHMM.sql`,
et extraire le zip dans `public/`.

## Données de départ

Les fixtures reprennent les informations publiques du club (président, gymnases, équipes 2025-2026, label).
Matchs, créneaux, tarifs et partenaires sont **des exemples** à remplacer depuis l'espace admin du site.

## Images envoyées

`POST /api/medias` range les fichiers dans `public/uploads/AAAA/MM/` (JPG, PNG, WebP, GIF, PDF ; 10 Mo max) et
renvoie leur URL. Les images sont réencodées sans métadonnées ; les PDF sont vérifiés puis enregistrés tels quels. Le site réduit les photos à 2000 px avant l'envoi. En production, si l'API est derrière
un autre nom de domaine que celui vu par le serveur, définir `UPLOADS_BASE_URL` (ex. `https://api.ajso-handball.fr`).
Vérifier aussi `upload_max_filesize` / `post_max_size` dans `php.ini` (8M / 10M ; `public/.user.ini` le fait pour PHP-FPM).

## Import automatique FFHandball

Récupère matchs, horaires, salles, scores et classement sur ffhandball.fr (données lues dans le HTML des pages).

1. Admin du site → **Championnats** : coller l'adresse de la page de la poule
   (`https://www.ffhandball.fr/competitions/…/poule-123456/`). L'adresse de la compétition seule suffit :
   la poule de l'équipe est alors cherchée parmi ses poules au premier import, puis mémorisée.
   Une équipe peut avoir plusieurs compétitions (championnat, phase 1, phase 2, coupe) : toutes sont importées.
2. Admin → **Équipes** : saisir le **nom exact** de l'équipe sur ffhandball.fr (ex. `AJS OUISTREHAM HANDBALL 2`).
3. Lancer l'import : bouton **Import FFHandball** de l'admin, ou en ligne de commande :

```bash
php bin/console app:ffhb:import              # toutes les équipes configurées
php bin/console app:ffhb:import --equipe=12  # une seule
```

Pour chaque match joué, la **feuille de match** (PDF FDME) est ensuite téléchargée et lue : joueurs de notre
équipe, numéros, buts, buts sur 7 m, tirs, arrêts, exclusions et cartons (25 feuilles au plus par passage ;
les numéros de licence ne sont jamais conservés). Les joueurs sont créés automatiquement.

Si le code de la feuille manque dans la liste des matchs, il est cherché sur la page du match.
`php bin/console app:ffhb:etat` affiche, par équipe : matchs joués, feuilles disponibles et lues, joueurs, statistiques.

Les matchs importés sont reconnus par leur identifiant fédéral (pas de doublon) ; les adversaires sont créés
au besoin ; les journées closes ne sont pas relues ; une pause d'1 s sépare deux pages (`FFHB_PAUSE`).
Seules les adresses de `FFHB_HOSTS` (par défaut `www.ffhandball.fr`) sont acceptées.

Les matchs qui disparaissent du calendrier fédéral (annulés, changés de poule) sont retirés s'ils n'ont ni
score ni composition. Sur la feuille de match, le côté de notre équipe est reconnu par son nom, sinon par le
total des buts. En cas d'erreur, un résumé est envoyé à `ADMIN_EMAIL`.

**Planifier** l'import : voir [Tâches planifiées](#tâches-planifiées).

## Effectifs et vie privée

Le nom de famille d'un joueur n'est jamais publié tel quel : l'API expose `nomAffiche`, complet pour les
équipes dont la tranche d'âge commence à 18 ans, réduit à l'initiale (« Léa M. ») pour les jeunes.
Le nom complet n'est lisible que par l'admin (`/api/admin/joueurs`).

## Équipes de la saison (tableau)

Le tableau du club (`docs/equipes-2026-2027.csv`, une ligne par compétition) crée ou met à jour équipes,
catégories, compétitions, ententes, entraîneurs, salles et créneaux :

```bash
php bin/console app:import-equipes docs/equipes-2026-2027.csv --dry-run   # vérification
php bin/console app:import-equipes docs/equipes-2026-2027.csv
```

Colonnes : `Équipe;Catégorie;Nom FFHB;Compétition;Type;Adresse poule;Entente;Entraîneur;Créneaux`.
Créneaux : `Lundi 21h-22h Cosec / Mardi 20h30-22h Kieffer`. Relancer met à jour sans doublon.
Les fixtures (`doctrine:fixtures:load`) chargent ce tableau.

## Compositions de match et statistiques joueurs

`docs/compositions-2026-2027.csv` (une ligne par joueur et par match) crée les joueurs et enregistre
les compositions et les buts ; la fiche équipe affiche alors meilleurs buteurs, matchs joués et moyennes.

```bash
php bin/console app:import-compositions docs/compositions-2026-2027.csv
```

Colonnes : `Équipe;Match;Adversaire;N°;Joueur;Buts` — Match = journée (`J3`) ou date, Adversaire facultatif
(aide à retrouver le match). Les compositions se rattachent aux matchs importés de ffhandball.fr : lancer
`app:ffhb:import` avant. Le total des buts est comparé au score officiel. Relancer ne crée pas de doublon.

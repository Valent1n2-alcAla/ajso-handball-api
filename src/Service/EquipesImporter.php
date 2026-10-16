<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Championnat;
use App\Entity\Creneau;
use App\Entity\Equipe;
use App\Entity\Gymnase;
use App\Entity\Tranche;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée ou met à jour les équipes du club à partir du tableau de saison (CSV, séparateur « ; » ou « , »).
 *
 * Colonnes : Équipe ; Catégorie ; Nom FFHB ; Compétition ; Type ; Adresse poule ; Entente ; Entraîneur ; Créneaux
 * Une ligne par compétition : la première ligne d'une équipe donne son championnat principal,
 * les suivantes (Phase 2, Coupe…) s'ajoutent à ses autres compétitions.
 * Créneaux : « Lundi 21h-22h Cosec / Mardi 20h30-22h Kieffer » (séparés par « / » ou « ; »).
 * Relancer l'import met à jour sans créer de doublon (équipes reconnues par leur nom).
 */
class EquipesImporter
{
    private const COLONNES = ['equipe', 'categorie', 'nom ffhb', 'competition', 'type', 'adresse poule', 'entente', 'entraineur', 'creneaux'];
    private const JOURS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];
    private const TYPES = ['championnat' => 'championnat', 'phase 1' => 'phase-1', 'phase 2' => 'phase-2', 'coupe' => 'coupe'];

    /** Noms usuels des salles → nom officiel (et adresse quand elle est connue) */
    private const SALLES = [
        'cosec' => ['COSEC Maurice Chevalier', 'Avenue du Général Leclerc'],
        'kieffer' => ['Salle Stade Kieffer', '85 avenue du Général Leclerc'],
        'halle' => ['Halle des Sports', null],
        'maitrise' => ['Salle de la Maîtrise', null],
    ];

    /** @var array<string, object> */
    private array $cache = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{equipes: list<string>, creneaux: int, avertissements: list<string>}
     */
    public function importer(string $chemin): array
    {
        $lignes = $this->lire($chemin);
        $res = ['equipes' => [], 'creneaux' => 0, 'avertissements' => []];
        $vues = [];

        foreach ($lignes as $n => $l) {
            $nom = $l['equipe'];
            if ($nom === '') {
                continue;
            }
            $equipe = $this->equipe($nom);
            $premiere = !isset($vues[$nom]);
            $vues[$nom] = true;

            $categorie = $l['categorie'] !== '' ? $this->categorie($l['categorie']) : $equipe->getCategorie();
            $equipe->setEstAjso(true)->setCategorie($categorie);
            if ($l['nom ffhb'] !== '') {
                $equipe->setNomFfhb($l['nom ffhb']);
            }
            if ($premiere) {
                $equipe->setOrdre(count($res['equipes']))
                    ->setEntraineur($l['entraineur'] !== '' ? $l['entraineur'] : null)
                    ->setEntente(self::entente($l['entente']));
                $res['equipes'][] = $nom;
            }

            if ($l['competition'] !== '') {
                $championnat = $this->championnat($l['competition'], $l['adresse poule'], $l['type']);
                if ($premiere) {
                    $equipe->setChampionnat($championnat);
                } elseif ($championnat !== $equipe->getChampionnat()) {
                    $equipe->addCompetition($championnat);
                }
            }

            if ($premiere && $l['creneaux'] !== '') {
                // Le tableau fait foi : les créneaux de l'équipe sont remplacés
                if ($equipe->getId()) {
                    foreach ($this->em->getRepository(Creneau::class)->findBy(['equipe' => $equipe]) as $ancien) {
                        $this->em->remove($ancien);
                    }
                }
                foreach (preg_split('#\s*[/;]\s*#', $l['creneaux']) as $texte) {
                    $creneau = $this->creneau($equipe, $texte, $res['avertissements'], $n + 2);
                    if ($creneau) {
                        $this->em->persist($creneau);
                        ++$res['creneaux'];
                    }
                }
            }
        }

        return $res;
    }

    /** @return list<array<string, string>> */
    private function lire(string $chemin): array
    {
        $contenu = is_readable($chemin) ? file_get_contents($chemin) : false;
        if ($contenu === false) {
            throw new \InvalidArgumentException("Fichier illisible : $chemin");
        }
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252'); // CSV Excel « classique »
        }
        $contenu = preg_replace('/^\x{FEFF}/u', '', $contenu);
        $premiereLigne = strtok($contenu, "\n");
        $sep = substr_count($premiereLigne, ';') >= substr_count($premiereLigne, ',') ? ';' : ',';

        $flux = fopen('php://memory', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);
        $entete = array_map(fn ($c) => self::cle($c), fgetcsv($flux, null, $sep, '"', '') ?: []);
        if ($manque = array_diff(self::COLONNES, $entete)) {
            throw new \InvalidArgumentException('Colonnes manquantes : '.implode(', ', $manque));
        }
        $lignes = [];
        while (($cols = fgetcsv($flux, null, $sep, '"', '')) !== false) {
            $cols = array_map(fn ($c) => trim(preg_replace('/\s+/u', ' ', (string) $c)), $cols);
            $l = array_combine($entete, array_pad(array_slice($cols, 0, count($entete)), count($entete), ''));
            if (implode('', $l) !== '') {
                $lignes[] = $l;
            }
        }
        fclose($flux);

        return $lignes;
    }

    private function equipe(string $nom): Equipe
    {
        return $this->cache["e:$nom"] ??= $this->em->getRepository(Equipe::class)->findOneBy(['nom' => $nom, 'estAjso' => true])
            ?? self::tap(new Equipe(), fn (Equipe $e) => $this->em->persist($e->setNom($nom)));
    }

    private function categorie(string $nom): Categorie
    {
        $tranche = $this->tranche($nom);

        return $this->cache['c:'.mb_strtolower($nom)] ??= self::tap(
            $this->em->getRepository(Categorie::class)->findOneBy(['nom' => $nom]) ?? (new Categorie())->setNom($nom),
            fn (Categorie $c) => $this->em->persist($c->setTranche($tranche)),
        );
    }

    /** Tranche d'âge déduite de la catégorie : seniors / -13 à -17 / école de hand. */
    private function tranche(string $categorie): Tranche
    {
        $c = self::cle($categorie);
        [$nom, $min, $max] = match (true) {
            str_contains($c, 'senior') || str_contains($c, '+16') || str_contains($c, 'plus 16') || str_contains($c, 'loisir') => ['Adultes', 18, 99],
            (bool) preg_match('/-\s?1[3-8]\b/', $c) => ['Jeunes', 11, 17],
            default => ['École de hand', 3, 10],
        };

        return $this->cache["t:$nom"] ??= $this->em->getRepository(Tranche::class)->findOneBy(['nom' => $nom])
            ?? self::tap((new Tranche())->setNom($nom)->setAgeMin($min)->setAgeMax($max), fn ($t) => $this->em->persist($t));
    }

    private function championnat(string $nom, string $url, string $type): Championnat
    {
        $url = filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
        $cle = 'ch:'.($url ?? mb_strtolower($nom));
        $repo = $this->em->getRepository(Championnat::class);
        $championnat = $this->cache[$cle] ??= ($url ? $repo->findOneBy(['urlFfhb' => $url]) : null)
            ?? $repo->findOneBy(['nom' => $nom])
            ?? new Championnat();

        $niveau = match (true) {
            $url && str_contains($url, '/regional/'), (bool) preg_match('/r[ée]gion/i', $nom) => 'Régional',
            $url && str_contains($url, '/national/') => 'National',
            default => 'Départemental',
        };
        // Une poule déjà trouvée par l'import (adresse plus précise) n'est pas écrasée par l'adresse de la compétition
        $garderPoule = $url && $championnat->getUrlFfhb() && str_starts_with($championnat->getUrlFfhb(), rtrim($url, '/'));
        $championnat->setNom(trim(preg_replace('/\(\s*(.*?)\s*\)/u', '($1)', $nom)))
            ->setNiveau($niveau)
            ->setType(self::TYPES[self::cle($type)] ?? 'championnat');
        if (!$garderPoule) {
            $championnat->setUrlFfhb($url);
        }
        $this->em->persist($championnat);

        return $championnat;
    }

    /** @param list<string> $avertissements */
    private function creneau(Equipe $equipe, string $texte, array &$avertissements, int $ligne): ?Creneau
    {
        $re = '/^(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+(\d{1,2})\s*[h:]\s*(\d{2})?\s*-\s*(\d{1,2})\s*[h:]\s*(\d{2})?\s*(.*)$/u';
        if (!preg_match($re, self::cle($texte), $m)) {
            $avertissements[] = sprintf('Ligne %d (%s) : créneau illisible « %s »', $ligne, $equipe->getNom(), $texte);

            return null;
        }
        // Le nom de salle est relu dans le texte original (accents et majuscules conservés)
        $salle = trim(preg_replace('/^.*?\d{1,2}\s*[h:]\s*\d{0,2}\s*-\s*\d{1,2}\s*[h:]\s*\d{0,2}/u', '', $texte));
        if ($salle === '') {
            $avertissements[] = sprintf('Ligne %d (%s) : salle manquante pour « %s » — créneau ignoré', $ligne, $equipe->getNom(), $texte);

            return null;
        }

        return (new Creneau())
            ->setEquipe($equipe)
            ->setGymnase($this->gymnase($salle))
            ->setJour(self::JOURS[$m[1]])
            ->setHeureDebut(new \DateTime(sprintf('%02d:%02d', $m[2], $m[3] ?: 0)))
            ->setHeureFin(new \DateTime(sprintf('%02d:%02d', $m[4], $m[5] ?: 0)));
    }

    private function gymnase(string $salle): Gymnase
    {
        $cle = self::cle($salle);
        [$nom, $adresse] = [$salle, null];
        foreach (self::SALLES as $motCle => $officiel) {
            if (str_contains($cle, $motCle)) {
                [$nom, $adresse] = $officiel;
                break;
            }
        }

        return $this->cache['g:'.mb_strtolower($nom)] ??= $this->em->getRepository(Gymnase::class)->findOneBy(['nom' => $nom])
            ?? self::tap((new Gymnase())->setNom($nom)->setAdresse($adresse ?? 'Adresse à préciser')->setCodePostal('14150')->setVille('Ouistreham'),
                fn ($g) => $this->em->persist($g));
    }

    /** « Oui ( Douvres) » → « Douvres » ; « Non » → null */
    private static function entente(string $valeur): ?string
    {
        $c = self::cle($valeur);
        if ($c === '' || str_starts_with($c, 'non')) {
            return null;
        }

        return preg_match('/\(\s*(.*?)\s*\)/u', $valeur, $m) && $m[1] !== '' ? $m[1] : 'Oui';
    }

    /**
     * Applique $fn à $valeur puis la renvoie (création « en ligne » dans les ??=).
     *
     * @template T
     *
     * @param T $valeur
     *
     * @return T
     */
    private static function tap(mixed $valeur, callable $fn): mixed
    {
        $fn($valeur);

        return $valeur;
    }

    /** Minuscules sans accents ni espaces superflus, pour comparer en-têtes et mots-clés. */
    private static function cle(?string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));

        return strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c']);
    }
}

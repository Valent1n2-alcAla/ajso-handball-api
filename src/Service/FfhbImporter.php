<?php

namespace App\Service;

use App\Entity\Championnat;
use App\Entity\Classement;
use App\Entity\Equipe;
use App\Entity\Rencontre;
use App\Repository\ClassementRepository;
use App\Repository\EquipeRepository;
use App\Repository\RencontreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Import des matchs et du classement d'une équipe depuis ffhandball.fr.
 *
 * Chaque page de la fédération sert ses données en JSON dans le HTML :
 *   <smartfire-component name="competitions---rencontre-list" attributes="{…json…}">
 * Pages lues, à partir de l'URL de la poule (Championnat::urlFfhb) :
 *   poule-<id>/              calendrier des journées, journée en cours, classement
 *   poule-<id>/journee-<n>/  rencontres de la journée n (seulement les journées pas encore closes)
 *   poule-<id>/rencontre-<id>/  gymnase des prochains matchs à domicile, une fois fixé
 */
class FfhbImporter
{
    private const BLOC = '/<smartfire-component\s+name=([\'"])([\w-]+)\1\s+attributes="([^"]*)"/';
    private const LIEU_INCONNU = 'À définir';
    private const SALLES_PAR_IMPORT = 4;

    /** @var list<string> */
    private array $hotes;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly EquipeRepository $equipes,
        private readonly RencontreRepository $rencontres,
        private readonly ClassementRepository $classements,
        #[Autowire('%env(FFHB_HOSTS)%')] string $hotes,
        /** Pause entre deux pages (secondes) : rester léger avec le site de la fédération */
        #[Autowire('%env(float:FFHB_PAUSE)%')] private readonly float $pause = 1.0,
    ) {
        $this->hotes = array_filter(array_map('trim', explode(',', $hotes)));
    }

    /**
     * Importe toutes les compétitions de l'équipe (championnat principal, phases, coupe).
     *
     * @return array{equipe: string, crees: int, maj: int, classement: int, pages: int, competitions: int, ignorees: list<string>}
     */
    public function importer(Equipe $equipe): array
    {
        $libelle = $equipe->getNomFfhb();
        $competitions = array_values(array_filter($equipe->toutesCompetitions(), fn (Championnat $c) => (bool) $c->getUrlFfhb()));
        if (!$libelle || !$competitions) {
            throw new \InvalidArgumentException(sprintf('« %s » : renseignez le nom FFHandball de l\'équipe et l\'adresse ffhandball.fr d\'au moins une de ses compétitions.', $equipe->getNom()));
        }

        $stats = ['equipe' => $equipe->getNom(), 'crees' => 0, 'maj' => 0, 'classement' => 0, 'pages' => 0, 'competitions' => 0, 'ignorees' => []];
        $pages = 0;
        foreach ($competitions as $championnat) {
            try {
                $this->importerCompetition($equipe, $championnat, $stats, $pages);
                ++$stats['competitions'];
            } catch (\InvalidArgumentException $e) {
                $stats['ignorees'][] = $championnat->getNom().' : '.$e->getMessage();
            }
        }
        $stats['pages'] = $pages;
        if ($stats['competitions'] === 0) {
            throw new \InvalidArgumentException(implode(' ; ', $stats['ignorees']));
        }

        return $stats;
    }

    /**
     * Adresse de la poule où joue l'équipe. Accepte aussi la page d'une compétition (la poule est cherchée
     * parmi celles de la compétition, puis enregistrée) ou d'une journée (ramenée à la poule).
     */
    private function poule(Championnat $championnat, string $nous, int &$pages): string
    {
        $url = trim((string) $championnat->getUrlFfhb());
        $hote = parse_url($url, PHP_URL_HOST);
        if (!in_array($hote, $this->hotes, true)) {
            throw new \InvalidArgumentException(sprintf('adresse refusée (%s) : seules les pages de %s sont acceptées.', $hote ?: $url, implode(', ', $this->hotes)));
        }
        if (preg_match('#^(.*?/poule-\d+)(?:/|$)#', $url, $m)) {
            return $m[1].'/';
        }

        // Page de la compétition : on parcourt ses poules jusqu'à trouver l'équipe
        $base = rtrim($url, '/').'/';
        $liste = ($this->page($base, $pages)['competitions---poule-selector'] ?? [])['poules'] ?? [];
        foreach ($liste as $p) {
            if (empty($p['ext_pouleId'])) {
                continue;
            }
            $candidat = "{$base}poule-{$p['ext_pouleId']}/";
            $blocs = $this->page($candidat, $pages);
            if ($this->contientEquipe($blocs, $nous)) {
                $championnat->setUrlFfhb($candidat); // mémorisée : la recherche n'est faite qu'une fois

                return $candidat;
            }
        }

        throw new \InvalidArgumentException(count($liste)
            ? sprintf('équipe introuvable dans les %d poules de la compétition (vérifiez le nom FFHandball).', count($liste))
            : 'aucune poule publiée pour cette compétition pour l\'instant.');
    }

    private function contientEquipe(array $blocs, string $nous): bool
    {
        foreach (($blocs['competitions---mini-classement-or-ads'] ?? $blocs['competitions---classement'] ?? [])['classements'] ?? [] as $l) {
            if (self::normaliser($l['equipe_libelle'] ?? '') === $nous) {
                return true;
            }
        }
        foreach (($blocs['competitions---rencontre-list'] ?? [])['rencontres'] ?? [] as $r) {
            if (in_array($nous, [self::normaliser($r['equipe1Libelle'] ?? ''), self::normaliser($r['equipe2Libelle'] ?? '')], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{crees: int, maj: int, classement: int} $stats
     */
    private function importerCompetition(Equipe $equipe, Championnat $championnat, array &$stats, int &$pages): void
    {
        $nous = self::normaliser($equipe->getNomFfhb());
        $base = $this->poule($championnat, $nous, $pages);

        $poule = $this->page($base, $pages);
        $journees = $this->journees($poule);

        // Classement officiel : remplacé en bloc
        $lignes = ($poule['competitions---mini-classement-or-ads'] ?? $poule['competitions---classement'] ?? [])['classements'] ?? [];
        if ($lignes) {
            foreach ($this->classements->findBy(['championnat' => $championnat]) as $ancien) {
                $this->em->remove($ancien);
            }
            foreach ($lignes as $l) {
                $nom = self::nettoyer($l['equipe_libelle'] ?? '');
                if ($nom === '') {
                    continue;
                }
                $this->em->persist((new Classement())
                    ->setChampionnat($championnat)
                    ->setPosition((int) ($l['place'] ?? 0) ?: 99)
                    ->setEquipe($nom)
                    ->setEstAjso(self::normaliser($nom) === $nous)
                    ->setPoints((int) ($l['point'] ?? 0))
                    ->setJoues((int) ($l['joue'] ?? 0))
                    ->setGagnes((int) ($l['gagne'] ?? 0))
                    ->setNuls((int) ($l['nul'] ?? 0))
                    ->setPerdus((int) ($l['perdu'] ?? 0))
                    ->setButsPour((int) ($l['butPlus'] ?? 0))
                    ->setButsContre((int) ($l['butMoins'] ?? 0)));
                ++$stats['classement'];
            }
        }

        // Rencontres : la journée affichée sur la page de la poule, puis les journées pas encore closes
        $listes = [];
        $courante = $poule['competitions---rencontre-list'] ?? [];
        if (ctype_digit((string) ($courante['selected_numero_journee'] ?? ''))) {
            $listes[(int) $courante['selected_numero_journee']] = $courante['rencontres'] ?? [];
        }
        foreach (array_keys($journees) as $n) {
            if (isset($listes[$n]) || $this->journeeClose($equipe, $championnat, $n)) {
                continue;
            }
            $listes[$n] = ($this->page("{$base}journee-$n/", $pages)['competitions---rencontre-list'] ?? [])['rencontres'] ?? [];
        }

        $adversaires = [];
        foreach ($listes as $n => $rencontres) {
            foreach ($rencontres as $r) {
                $dom = self::nettoyer($r['equipe1Libelle'] ?? '');
                $ext = self::nettoyer($r['equipe2Libelle'] ?? '');
                $estDom = self::normaliser($dom) === $nous;
                if (!$estDom && self::normaliser($ext) !== $nous) {
                    continue;
                }
                $id = (string) ($r['ext_rencontreId'] ?? '');
                if ($id === '') {
                    continue;
                }

                $nomAdv = $estDom ? $ext : $dom;
                $adversaire = $adversaires[$nomAdv] ??= $this->adversaire($nomAdv, $equipe);

                $match = $this->rencontres->findOneBy(['ffhbId' => $id]);
                $nouveau = !$match;
                $match ??= (new Rencontre())->setFfhbId($id)->setLieu(self::LIEU_INCONNU);

                [$date, $heure] = self::dateHeure($r['date'] ?? null);
                if ($date) {
                    $match->setDate($date)->setHeure($heure)->setDateConfirmee(true);
                } elseif (isset($journees[$n]) && ($debut = self::dateHeure($journees[$n])[0])) {
                    $match->setDate($debut)->setHeure(null)->setDateConfirmee(false);
                } elseif ($nouveau) {
                    continue; // ni date ni journée datée : on attend la prochaine publication
                }

                $match->setEquipe($equipe)
                    ->setChampionnat($championnat)
                    ->setEquipeAdverse($adversaire)
                    ->setDomicile($estDom)
                    ->setJournee("J$n")
                    ->setScoreDom(self::score($r['equipe1Score'] ?? null))
                    ->setScoreExt(self::score($r['equipe2Score'] ?? null));
                if ($match->getLieu() === self::LIEU_INCONNU && (!$estDom || $match->getDate() < new \DateTime('today'))) {
                    // salle lue seulement pour les prochains matchs à domicile (completerSalles)
                    $match->setLieu($estDom ? 'Ouistreham' : $nomAdv);
                }

                $this->em->persist($match);
                ++$stats[$nouveau ? 'crees' : 'maj'];
            }
        }
        $this->em->flush();

        $this->completerSalles($equipe, $championnat, $base, $pages);
        $this->em->flush();
    }

    /** Gymnase des prochains matchs dont le lieu est inconnu, lu sur la page de la rencontre. */
    private function completerSalles(Equipe $equipe, Championnat $championnat, string $base, int &$pages): void
    {
        $prochains = $this->rencontres->createQueryBuilder('r')
            ->where('r.equipe = :e')->andWhere('r.championnat = :c')->andWhere('r.ffhbId IS NOT NULL')->andWhere('r.scoreDom IS NULL')
            ->andWhere('r.lieu = :inconnu')->andWhere('r.date >= :auj')
            ->setParameters(new \Doctrine\Common\Collections\ArrayCollection([
                new \Doctrine\ORM\Query\Parameter('e', $equipe),
                new \Doctrine\ORM\Query\Parameter('c', $championnat),
                new \Doctrine\ORM\Query\Parameter('inconnu', self::LIEU_INCONNU),
                new \Doctrine\ORM\Query\Parameter('auj', new \DateTime('today')),
            ]))
            ->orderBy('r.date', 'ASC')->setMaxResults(self::SALLES_PAR_IMPORT)
            ->getQuery()->getResult();

        foreach ($prochains as $match) {
            $salle = ($this->page("{$base}rencontre-{$match->getFfhbId()}/", $pages)['competitions---rencontre-salle'] ?? [])['equipement'] ?? [];
            $nom = self::nettoyer($salle['libelle'] ?? '');
            if ($nom !== '') {
                $ville = self::nettoyer($salle['ville'] ?? '');
                $match->setLieu(mb_substr($ville ? "$nom, $ville" : $nom, 0, 150));
            }
        }
    }

    /** Une journée est close quand tous nos matchs de cette journée sont passés et ont un score. */
    private function journeeClose(Equipe $equipe, Championnat $championnat, int $n): bool
    {
        $matchs = $this->rencontres->findBy(['equipe' => $equipe, 'championnat' => $championnat, 'journee' => "J$n"]);
        if (!$matchs) {
            return false;
        }
        foreach ($matchs as $m) {
            if ($m->getScoreDom() === null || $m->getDate() >= new \DateTime('today')) {
                return false;
            }
        }

        return true;
    }

    private function adversaire(string $nom, Equipe $equipe): Equipe
    {
        $existant = $this->equipes->createQueryBuilder('e')
            ->where('LOWER(e.nom) = :nom')->andWhere('e.estAjso = false')
            ->setParameter('nom', mb_strtolower($nom))
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($existant) {
            return $existant;
        }
        $nouvelle = (new Equipe())->setNom(mb_substr($nom, 0, 100))->setEstAjso(false)->setCategorie($equipe->getCategorie());
        $this->em->persist($nouvelle);

        return $nouvelle;
    }

    /** @return array<int, string> numéro de journée => date de début */
    private function journees(array $page): array
    {
        $poule = ($page['competitions---journee-selector'] ?? [])['poule'] ?? ($page['competitions---rencontre-list'] ?? [])['poule'] ?? [];
        $liste = is_string($poule['journees'] ?? null) ? json_decode($poule['journees'], true) : ($poule['journees'] ?? []);
        $out = [];
        foreach ((array) $liste as $j) {
            if (ctype_digit((string) ($j['journee_numero'] ?? ''))) {
                $out[(int) $j['journee_numero']] = (string) ($j['date_debut'] ?? '');
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, mixed> blocs « competitions--- » de la page */
    private function page(string $url, int &$pages): array
    {
        if ($pages > 0 && $this->pause > 0) {
            usleep((int) ($this->pause * 1_000_000));
        }
        ++$pages;
        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => 'ajso-handball-site/1.0 (import calendrier du club)'],
            'timeout' => 30,
        ])->getContent();

        $blocs = [];
        preg_match_all(self::BLOC, $html, $m, PREG_SET_ORDER);
        foreach ($m as [, , $nom, $attributs]) {
            if (str_starts_with($nom, 'competitions---') && !isset($blocs[$nom])) {
                $data = json_decode(html_entity_decode($attributs, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (is_array($data)) {
                    $blocs[$nom] = $data;
                }
            }
        }

        return $blocs;
    }

    /** « 2026-10-03T21:00:00+02:00 » → [date, heure] à l'heure locale affichée par le site. */
    private static function dateHeure(?string $valeur): array
    {
        if (!$valeur || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $valeur, $m)) {
            return [null, null];
        }
        $heure = isset($m[2]) && $m[2] !== '00:00' ? \DateTime::createFromFormat('!H:i', $m[2]) : null;

        return [\DateTime::createFromFormat('!Y-m-d', $m[1]) ?: null, $heure ?: null];
    }

    private static function score(mixed $v): ?int
    {
        return preg_match('/^\d{1,3}$/', (string) $v) ? (int) $v : null;
    }

    private static function nettoyer(?string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $s));
    }

    /** Comparaison de libellés insensible à la casse, aux accents et aux espaces. */
    public static function normaliser(?string $s): string
    {
        $s = mb_strtoupper(self::nettoyer($s));

        return strtr(\Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s, ["\u{0301}" => '', "\u{0300}" => '', "\u{0302}" => '', "\u{0308}" => '', "\u{0327}" => '']);
    }
}

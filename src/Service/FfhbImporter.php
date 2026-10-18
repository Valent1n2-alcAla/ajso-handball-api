<?php

namespace App\Service;

use App\Entity\Championnat;
use App\Entity\Classement;
use App\Entity\Equipe;
use App\Entity\Participation;
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

    private const FEUILLES_PAR_IMPORT = 25;

    /** @var list<string> */
    private array $hotes;

    private int $feuillesLues = 0;

    /** Dossier où enregistrer les pages lues (mode diagnostic) */
    private ?string $dossierDebug = null;

    public function activerDebug(string $dossier): void
    {
        $this->dossierDebug = rtrim($dossier, '/\\');
    }

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly EquipeRepository $equipes,
        private readonly RencontreRepository $rencontres,
        private readonly ClassementRepository $classements,
        private readonly FeuilleMatchLecteur $feuilles,
        private readonly CompositionsImporter $compositions,
        #[Autowire('%env(FFHB_HOSTS)%')] string $hotes,
        /** Pause entre deux pages (secondes) : rester léger avec le site de la fédération */
        #[Autowire('%env(float:FFHB_PAUSE)%')] private readonly float $pause = 1.0,
        /** Serveur des feuilles de match FDME */
        #[Autowire('%env(FFHB_FEUILLES_URL)%')] private readonly string $urlFeuilles = 'https://fdm.fdme.ffhandball.fr/',
    ) {
        $this->hotes = array_filter(array_map('trim', explode(',', $hotes)));
    }

    /**
     * Importe toutes les compétitions de l'équipe (championnat principal, phases, coupe).
     *
     * @return array{equipe: string, crees: int, maj: int, classement: int, pages: int, competitions: int, feuilles: int, joueurs: int, ignorees: list<string>, infos: list<string>}
     */
    public function importer(Equipe $equipe): array
    {
        $libelle = $equipe->getNomFfhb();
        $competitions = array_values(array_filter($equipe->toutesCompetitions(), fn (Championnat $c) => (bool) $c->getUrlFfhb()));
        if (!$libelle || !$competitions) {
            throw new \InvalidArgumentException(sprintf('« %s » : renseignez le nom FFHandball de l\'équipe et l\'adresse ffhandball.fr d\'au moins une de ses compétitions.', $equipe->getNom()));
        }

        $stats = ['equipe' => $equipe->getNom(), 'crees' => 0, 'maj' => 0, 'classement' => 0, 'pages' => 0, 'competitions' => 0, 'feuilles' => 0, 'joueurs' => 0, 'ignorees' => [], 'infos' => []];
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
        $vus = [];
        foreach ($liste as $p) {
            if (empty($p['ext_pouleId'])) {
                continue;
            }
            $candidat = "{$base}poule-{$p['ext_pouleId']}/";
            $noms = self::libelles($this->page($candidat, $pages));
            $vus = array_merge($vus, $noms);
            if (self::resoudreNom($noms, $nous) !== null) {
                $championnat->setUrlFfhb($candidat); // mémorisée : la recherche n'est faite qu'une fois

                return $candidat;
            }
        }

        throw new \InvalidArgumentException(count($liste)
            ? sprintf('« %s » introuvable dans les %d poule(s) de la compétition. Équipes présentes : %s', $nous, count($liste), self::apercu($vus))
            : 'aucune poule publiée pour cette compétition pour l\'instant.');
    }

    /** Libellés d'équipes présents sur une page (classement et rencontres). @return list<string> */
    private static function libelles(array $blocs): array
    {
        $noms = [];
        foreach (($blocs['competitions---mini-classement-or-ads'] ?? $blocs['competitions---classement'] ?? [])['classements'] ?? [] as $l) {
            $noms[] = self::nettoyer($l['equipe_libelle'] ?? '');
        }
        foreach (($blocs['competitions---rencontre-list'] ?? [])['rencontres'] ?? [] as $r) {
            $noms[] = self::nettoyer($r['equipe1Libelle'] ?? '');
            $noms[] = self::nettoyer($r['equipe2Libelle'] ?? '');
        }

        return array_values(array_unique(array_filter($noms)));
    }

    /**
     * Retrouve le libellé fédéral de l'équipe parmi ceux d'une poule : égalité (casse, accents, ponctuation
     * ignorés), sinon mêmes mots significatifs (« AJS OUISTREHAM HANDBALL » ≈ « OUISTREHAM AJS HB »,
     * « ENT. COTE DE NACRE » ≈ « ENTENTE COTE DE NACRE 1 ») quand un seul libellé correspond.
     *
     * @param list<string> $libelles
     *
     * @return string|null libellé normalisé trouvé
     */
    private static function resoudreNom(array $libelles, string $nous): ?string
    {
        $normes = array_values(array_unique(array_map(fn ($l) => self::normaliser($l), $libelles)));
        if (in_array($nous, $normes, true)) {
            return $nous;
        }
        $notres = self::jetons($nous);
        if (!$notres) {
            return null;
        }
        $candidats = array_values(array_filter($normes, function (string $n) use ($notres) {
            $siens = self::jetons($n);
            $communs = array_intersect($notres, $siens);

            return $siens && (count($communs) === count($notres) || count($communs) === count($siens));
        }));

        return count($candidats) === 1 ? $candidats[0] : null;
    }

    /** Mots significatifs d'un libellé (sans « HANDBALL », « HB », « ENTENTE »…, ni numéro d'équipe). @return list<string> */
    private static function jetons(string $libelle): array
    {
        $vides = ['HANDBALL', 'HAND', 'BALL', 'HB', 'HBC', 'CLUB', 'ENT', 'ENTENTE', 'ASSOCIATION', 'DE', 'DU', 'DES', 'LA', 'LE', 'LES', 'ET', 'L', 'D'];
        $mots = preg_split('/[^A-Z0-9]+/', self::normaliser($libelle), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($mots, fn ($m) => !in_array($m, $vides, true) && !preg_match('/^\d{1,2}$/', $m))));
    }

    /** @param list<string> $noms */
    private static function apercu(array $noms): string
    {
        $noms = array_values(array_unique($noms));
        sort($noms);

        return $noms ? implode(' | ', array_slice($noms, 0, 40)).(count($noms) > 40 ? ' …' : '') : '(aucune)';
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

        // Libellé réel de l'équipe dans cette poule
        $noms = self::libelles($poule);
        foreach ($listes as $rencontres) {
            $noms = array_merge($noms, self::libelles(['competitions---rencontre-list' => ['rencontres' => $rencontres]]));
        }
        $saisi = $nous;
        $nous = self::resoudreNom($noms, $saisi);
        if ($nous === null) {
            throw new \InvalidArgumentException(sprintf('« %s » introuvable dans la poule. Équipes présentes : %s', $saisi, self::apercu($noms)));
        }
        if ($nous !== $saisi) {
            $stats['infos'][] = sprintf('%s : nom reconnu sur ffhandball.fr « %s »', $championnat->getNom(), $nous);
        }

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

        $adversaires = [];
        $avant = $stats['crees'] + $stats['maj'];
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
                    ->setFdmCode(preg_match('/^[A-Z0-9]{5,}$/i', (string) ($r['fdmCode'] ?? '')) ? strtoupper($r['fdmCode']) : $match->getFdmCode())
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

        if ($stats['crees'] + $stats['maj'] === $avant) {
            $lues = array_merge(...array_values($listes) ?: [[]]);
            $stats['infos'][] = sprintf('%s : aucun match de « %s » parmi %d rencontres lues sur %d journée(s)%s',
                $championnat->getNom(), $nous, count($lues), count($listes),
                $lues ? ' (champs : '.implode(', ', array_keys((array) $lues[0])).')' : '');
        }

        $this->completerSalles($equipe, $championnat, $base, $pages);
        $this->em->flush();

        $this->lireFeuilles($equipe, $championnat, $stats);
        $this->em->flush();
    }

    /**
     * Feuilles de match des rencontres jouées pas encore lues : joueurs, buts, tirs, arrêts, sanctions.
     * Le serveur des feuilles limite le nombre de téléchargements : au plus FEUILLES_PAR_IMPORT par passage.
     */
    private function lireFeuilles(Equipe $equipe, Championnat $championnat, array &$stats): void
    {
        $aLire = $this->rencontres->createQueryBuilder('r')
            ->where('r.equipe = :e')->andWhere('r.championnat = :c')
            ->andWhere('r.fdmCode IS NOT NULL')->andWhere('r.scoreDom IS NOT NULL')
            ->andWhere('r.feuilleLue = false')
            ->setParameter('e', $equipe)->setParameter('c', $championnat)
            ->orderBy('r.date', 'ASC')->getQuery()->getResult();

        $sansCode = (int) $this->rencontres->createQueryBuilder('r')->select('COUNT(r.id)')
            ->where('r.equipe = :e')->andWhere('r.championnat = :c')
            ->andWhere('r.fdmCode IS NULL')->andWhere('r.scoreDom IS NOT NULL')
            ->setParameter('e', $equipe)->setParameter('c', $championnat)->getQuery()->getSingleScalarResult();
        if ($sansCode > 0) {
            $stats['infos'][] = sprintf('%d match(s) joué(s) sans code de feuille de match publié par la FFHB', $sansCode);
        }

        foreach ($aLire as $match) {
            if ($this->feuillesLues >= self::FEUILLES_PAR_IMPORT) {
                $stats['infos'][] = 'Feuilles de match : limite atteinte pour ce passage, la suite au prochain import.';

                return;
            }
            $code = $match->getFdmCode();
            $url = rtrim($this->urlFeuilles, '/').'/'.implode('/', str_split(substr($code, 0, 4))).'/'.$code.'.pdf';
            try {
                usleep((int) ($this->pause * 1_000_000));
                ++$this->feuillesLues;
                $reponse = $this->http->request('GET', $url, ['timeout' => 30, 'headers' => ['User-Agent' => 'ajso-handball-site/1.0']]);
                $statut = $reponse->getStatusCode();
                if ($statut === 404) {
                    continue; // feuille pas encore publiée
                }
                if ($statut === 429) {
                    $stats['infos'][] = 'Feuilles de match : le serveur demande de ralentir, la suite au prochain import.';
                    $this->feuillesLues = self::FEUILLES_PAR_IMPORT;

                    return;
                }
                $pdf = $reponse->getContent();
                if ($this->dossierDebug) {
                    @mkdir($this->dossierDebug, 0777, true);
                    file_put_contents("{$this->dossierDebug}/feuille-{$code}.pdf", $pdf);
                }
                $feuille = $this->feuilles->lire($pdf);
            } catch (\Throwable $e) {
                $stats['infos'][] = sprintf('Feuille %s (%s) illisible : %s', $code, $match->getDate()?->format('d/m'), $e->getMessage());
                continue;
            }

            $nos = $feuille[$match->isDomicile() ? 'recevant' : 'visiteur'];
            if (!$nos) {
                $stats['infos'][] = sprintf('Feuille %s : aucun joueur lu', $code);
                continue;
            }
            // La feuille officielle remplace une composition saisie à la main
            foreach ($this->em->getRepository(Participation::class)->findBy(['rencontre' => $match]) as $ancienne) {
                $this->em->remove($ancienne);
            }
            $this->em->flush();
            $total = 0;
            foreach ($nos as $l) {
                [$joueur, $nouveau] = $this->compositions->trouverJoueur($equipe, $l['prenom'], $l['nom']);
                $stats['joueurs'] += (int) $nouveau;
                $joueur->setNumero($l['numero']);
                $this->em->persist((new Participation())->setRencontre($match)->setJoueur($joueur)
                    ->setNumero($l['numero'])->setButs($l['buts'])->setButs7m($l['buts7m'])->setTirs($l['tirs'])
                    ->setArrets($l['arrets'])->setExclusions($l['exclusions'])
                    ->setAvertissement($l['avertissement'])->setDisqualification($l['disqualification']));
                $total += $l['buts'];
            }
            $match->setFeuilleLue(true);
            ++$stats['feuilles'];
            $score = $match->isDomicile() ? $match->getScoreDom() : $match->getScoreExt();
            if ($score !== null && $score !== $total) {
                $stats['infos'][] = sprintf('Feuille %s : %d buts lus pour %d au score, à vérifier', $code, $total, $score);
            }
            $this->em->flush();
        }
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

        if ($this->dossierDebug) {
            @mkdir($this->dossierDebug, 0777, true);
            file_put_contents(sprintf('%s/%03d-%s.html', $this->dossierDebug, $pages, trim(preg_replace('/[^a-z0-9]+/i', '-', parse_url($url, PHP_URL_PATH) ?? ''), '-')), $html);
        }

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

    /** Comparaison de libellés insensible à la casse, aux accents, à la ponctuation et aux espaces. */
    public static function normaliser(?string $s): string
    {
        $s = mb_strtoupper(self::nettoyer($s));
        $s = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s); // accents
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s); // ponctuation : « ENT. » = « ENT »

        return trim($s);
    }
}

<?php

namespace App\Service;

use Smalot\PdfParser\Parser;

/**
 * Lit le tableau des joueurs d'une feuille de match FDME (PDF de la FFHandball).
 *
 * Page 1 : pour chaque équipe (recevant puis visiteur), une ligne d'en-tête
 * « Capt | N° | NOM Prénom | Licence | Type | JFL | Buts | 7m | Tirs | Arrets | Av. | 2' | Dis »
 * puis une ligne par joueur. Les cases vides ne produisent aucun texte : chaque valeur est donc rattachée
 * à sa colonne par sa position horizontale. Les numéros de licence ne sont jamais conservés.
 *
 * Noms des deux équipes, quand la feuille les donne : ligne « RECEVANT / VISITEUR » au-dessus des tableaux,
 * ou lignes « Recevant : … » / « Visiteur : … ». Ils servent à reconnaître notre côté (voir FfhbImporter).
 */
class FeuilleMatchLecteur
{
    private const COLONNES = [
        'numero' => ['N°', 'N', 'NO'], 'nom' => ['NOM'], 'licence' => ['LICENCE'], 'buts' => ['BUTS'], 'buts7m' => ['7M'],
        'tirs' => ['TIRS'], 'arrets' => ['ARRETS', 'ARRÊTS', 'ARRET'], 'avertissement' => ['AV.', 'AV'],
        'exclusions' => ["2'", '2MN', '2'], 'disqualification' => ['DIS', 'DISQ'],
    ];

    /**
     * @return array{recevant: list<array<string, mixed>>, visiteur: list<array<string, mixed>>, noms: array{recevant: ?string, visiteur: ?string}}
     *                Joueur : numero, prenom, nom, buts, buts7m, tirs, arrets, exclusions, avertissement, disqualification
     */
    public function lire(string $pdf): array
    {
        if (PdfDechiffreur::estChiffre($pdf)) {
            $pdf = PdfDechiffreur::dechiffrer($pdf); // feuilles FDME protégées (sans mot de passe d'ouverture)
        }
        $pages = (new Parser())->parseContent($pdf)->getPages();
        if (!$pages) {
            throw new \RuntimeException('Feuille de match vide.');
        }

        // Cases de la page 1 regroupées par ligne (même ordonnée, à 2 points près)
        $lignes = [];
        foreach ($pages[0]->getDataTm() as [$matrice, $texte]) {
            $texte = trim((string) $texte);
            if ($texte === '') {
                continue;
            }
            $y = (float) $matrice[5];
            $cle = null;
            foreach (array_keys($lignes) as $k) {
                if (abs($k - $y) <= 2) {
                    $cle = $k;
                    break;
                }
            }
            $lignes[$cle ?? (string) $y][] = ['x' => (float) $matrice[4], 't' => $texte];
        }
        krsort($lignes, SORT_NUMERIC); // de haut en bas

        $equipes = [];
        $noms = ['recevant' => null, 'visiteur' => null];
        $colonnes = null;
        $apresOfficiels = false;
        foreach ($lignes as $cases) {
            usort($cases, fn ($a, $b) => $a['x'] <=> $b['x']);
            self::lireNoms($cases, $noms, $colonnes === null);
            $entete = $this->entete($cases);
            if ($entete) {
                $colonnes = $entete;
                if (!$equipes || end($equipes)) {
                    $equipes[] = []; // nouvelle équipe (sauf si déjà ouverte par la ligne « Officiel »)
                }
                $apresOfficiels = false;
                continue;
            }
            if ($colonnes === null) {
                continue;
            }
            // Les lignes « Officiel … » ferment le tableau d'une équipe : des joueurs après elles
            // appartiennent à l'équipe suivante, même si sa ligne de titres n'a pas été reconnue.
            if (preg_match('/^officiel/iu', $cases[0]['t'] ?? '') || preg_grep('/^officiel\s+resp/iu', array_column($cases, 't'))) {
                $apresOfficiels = true;
                continue;
            }
            $joueur = $this->joueur($cases, $colonnes);
            if ($joueur) {
                if ($apresOfficiels && end($equipes)) {
                    $equipes[] = [];
                }
                $apresOfficiels = false;
                $equipes[count($equipes) - 1][] = $joueur;
            }
        }

        if (!array_filter($equipes)) {
            // Diagnostic : début du texte de la page (licences masquées) pour adapter la lecture
            $texte = preg_replace('/\d{6,}/', '######', $pages[0]->getText());
            $texte = trim(preg_replace('/\s+/u', ' ', $texte));
            throw new \RuntimeException(sprintf('tableau des joueurs non reconnu (%d en-tête(s) trouvée(s)). Début de la feuille : « %s »', count($equipes), mb_substr($texte, 0, 400)));
        }

        return ['recevant' => $equipes[0] ?? [], 'visiteur' => $equipes[1] ?? [], 'noms' => $noms];
    }

    /**
     * Noms des équipes : « Recevant : NOM » / « Visiteur : NOM » n'importe où, ou « NOM A / NOM B » avant le premier
     * tableau (les scores, cases sans lettre, sont ignorés).
     *
     * @param list<array{x: float, t: string}>                $cases
     * @param array{recevant: ?string, visiteur: ?string} $noms
     */
    private static function lireNoms(array $cases, array &$noms, bool $avantTableaux): void
    {
        $texte = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter(array_column($cases, 't'), fn ($t) => preg_match('/\p{L}/u', $t)))));
        if ($texte === '') {
            return;
        }
        if (preg_match('/^(?:club |équipe |equipe )?(recevant|visiteur)e?s?\s*:\s*(.*\p{L}.*)$/iu', $texte, $m)) {
            $noms[mb_strtolower($m[1])] ??= self::nomEquipe($m[2]);

            return;
        }
        if ($avantTableaux && $noms['recevant'] === null && preg_match('#^(.*\p{L}.*?)\s+/\s+(.*\p{L}.*)$#u', $texte, $m)
            && !preg_match('/\b(code|renc|date|salle|comp[ée]tition|journ[ée]e)\b/iu', $m[1])) {
            $noms['recevant'] = self::nomEquipe($m[1]);
            $noms['visiteur'] = self::nomEquipe($m[2]);
        }
    }

    private static function nomEquipe(string $nom): ?string
    {
        $nom = trim(preg_replace('/^[\s:–-]+|[\s:–-]+$/u', '', $nom));

        return mb_strlen($nom) >= 3 ? mb_substr($nom, 0, 120) : null;
    }

    /** Ligne d'en-tête : abscisse de chaque colonne connue, ou null. @return array<string, float>|null */
    private function entete(array $cases): ?array
    {
        $cols = [];
        foreach ($cases as $i => $c) {
            $t = mb_strtoupper($c['t']);
            // « 7m » peut être écrit en deux morceaux (« 7 » puis « m »)
            if ($t === '7' && mb_strtoupper($cases[$i + 1]['t'] ?? '') === 'M' && $cases[$i + 1]['x'] - $c['x'] < 12) {
                $t = '7M';
            }
            foreach (self::COLONNES as $champ => $libelles) {
                if (!isset($cols[$champ]) && (in_array($t, $libelles, true) || ($champ === 'nom' && str_starts_with($t, 'NOM')))) {
                    $cols[$champ] = $c['x'];
                }
            }
        }

        // titres reconnus : Buts + (NOM ou Licence ou Tirs) — la cellule « NOM Prénom » peut être coupée sur deux lignes
        return isset($cols['buts']) && (isset($cols['nom']) || isset($cols['licence']) || isset($cols['tirs'])) ? $cols + ['nom' => ($cols['numero'] ?? 0) + 20] : null;
    }

    /**
     * Ligne joueur. N° et nom sont lus avant la colonne Licence (le titre « NOM Prénom » est centré alors que
     * les noms sont alignés à gauche) ; chaque statistique va à la colonne dont le titre est le plus proche
     * (valeurs centrées sous leur titre). Les numéros de licence sont ignorés.
     *
     * @param array<string, float> $colonnes
     */
    private function joueur(array $cases, array $colonnes): ?array
    {
        $stats = array_intersect_key($colonnes, array_flip(['buts', 'buts7m', 'tirs', 'arrets', 'avertissement', 'exclusions', 'disqualification']));
        $limiteIdentite = ($colonnes['licence'] ?? ($colonnes['buts'] - 60)) - 15;
        $debutStats = min($stats) - 15;

        $numero = null;
        $nom = null;
        $valeurs = [];
        foreach ($cases as $c) {
            $t = trim($c['t']);
            if ($t === '' || preg_match('/^\d{6,}$/', $t)) {
                continue; // licence
            }
            if ($c['x'] < $limiteIdentite) {
                if ($numero === null && preg_match('/^\d{1,2}$/', $t)) {
                    $numero = (int) $t;
                } elseif ($nom === null && preg_match('/\p{L}.*\p{L}/u', $t)) {
                    $nom = $t;
                }
                continue;
            }
            if ($c['x'] >= $debutStats) {
                $plusProche = null;
                foreach ($stats as $champ => $x) {
                    if ($plusProche === null || abs($c['x'] - $x) < abs($c['x'] - $stats[$plusProche])) {
                        $plusProche = $champ;
                    }
                }
                $valeurs[$plusProche] ??= $t;
            }
        }
        if ($numero === null || $nom === null) {
            return null; // officiels, lignes vides, score…
        }
        [$prenom, $nomFamille] = self::decouper($nom);

        $nombre = fn (string $champ) => preg_match('/\d+/', (string) ($valeurs[$champ] ?? ''), $m) ? (int) $m[0] : 0;
        $marque = fn (string $champ) => isset($valeurs[$champ]) && trim($valeurs[$champ]) !== '';

        return [
            'numero' => $numero, 'prenom' => $prenom, 'nom' => $nomFamille,
            'buts' => $nombre('buts'), 'buts7m' => $nombre('buts7m'), 'tirs' => $nombre('tirs'), 'arrets' => $nombre('arrets'),
            'exclusions' => $nombre('exclusions'), 'avertissement' => $marque('avertissement'), 'disqualification' => $marque('disqualification'),
        ];
    }

    /** « ROYER--CENARD Lucas » → ["Lucas", "Royer-Cenard"] ; « LE BEUVANT Julien » → ["Julien", "Le Beuvant"] */
    public static function decouper(string $nom): array
    {
        $mots = preg_split('/\s+/u', trim(preg_replace('/-{2,}/', '-', $nom)));
        $famille = [];
        while ($mots && mb_strtoupper($mots[0]) === $mots[0] && preg_match('/\p{L}/u', $mots[0])) {
            $famille[] = array_shift($mots);
        }
        if (!$mots) { // tout en majuscules : dernier mot = prénom
            $mots = [array_pop($famille)];
        }
        $casse = fn (string $s) => preg_replace_callback('/(^|[\s\'-])(\p{L})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), mb_strtolower($s));

        return [$casse(implode(' ', $mots)), $casse(implode(' ', $famille))];
    }
}

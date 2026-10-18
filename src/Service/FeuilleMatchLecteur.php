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
 */
class FeuilleMatchLecteur
{
    private const COLONNES = [
        'numero' => ['N°', 'N', 'NO'], 'nom' => ['NOM'], 'licence' => ['LICENCE'], 'buts' => ['BUTS'], 'buts7m' => ['7M'],
        'tirs' => ['TIRS'], 'arrets' => ['ARRETS', 'ARRÊTS', 'ARRET'], 'avertissement' => ['AV.', 'AV'],
        'exclusions' => ["2'", '2MN', '2'], 'disqualification' => ['DIS', 'DISQ'],
    ];

    /**
     * @return array{recevant: list<array<string, mixed>>, visiteur: list<array<string, mixed>>}
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
        $colonnes = null;
        foreach ($lignes as $cases) {
            usort($cases, fn ($a, $b) => $a['x'] <=> $b['x']);
            $entete = $this->entete($cases);
            if ($entete) {
                $colonnes = $entete;
                $equipes[] = [];
                continue;
            }
            if ($colonnes === null) {
                continue;
            }
            $joueur = $this->joueur($cases, $colonnes);
            if ($joueur) {
                $equipes[count($equipes) - 1][] = $joueur;
            }
        }

        return ['recevant' => $equipes[0] ?? [], 'visiteur' => $equipes[1] ?? []];
    }

    /** Ligne d'en-tête : abscisse de chaque colonne connue, ou null. @return array<string, float>|null */
    private function entete(array $cases): ?array
    {
        $cols = [];
        foreach ($cases as $c) {
            $t = mb_strtoupper($c['t']);
            foreach (self::COLONNES as $champ => $libelles) {
                if (!isset($cols[$champ]) && (in_array($t, $libelles, true) || ($champ === 'nom' && str_starts_with($t, 'NOM')))) {
                    $cols[$champ] = $c['x'];
                }
            }
        }

        return isset($cols['buts'], $cols['nom']) ? $cols : null;
    }

    /** @param array<string, float> $colonnes */
    private function joueur(array $cases, array $colonnes): ?array
    {
        asort($colonnes);
        $valeurs = [];
        foreach ($cases as $c) {
            // colonne = la dernière dont l'abscisse est à gauche de la case (cases alignées à gauche ou centrées)
            $champ = null;
            foreach ($colonnes as $nom => $x) {
                if ($c['x'] + 4 >= $x) {
                    $champ = $nom;
                }
            }
            if ($champ !== null && !isset($valeurs[$champ])) {
                $valeurs[$champ] = $c['t'];
            }
        }
        $numero = $valeurs['numero'] ?? '';
        $nom = trim($valeurs['nom'] ?? '');
        if (!preg_match('/^\d{1,2}$/', $numero) || $nom === '' || preg_match('/^\d+$/', $nom)) {
            return null; // officiels, lignes de score…
        }
        [$prenom, $nomFamille] = self::decouper($nom);

        $nombre = fn (string $champ) => preg_match('/\d+/', (string) ($valeurs[$champ] ?? ''), $m) ? (int) $m[0] : 0;
        $marque = fn (string $champ) => isset($valeurs[$champ]) && trim($valeurs[$champ]) !== '';

        return [
            'numero' => (int) $numero, 'prenom' => $prenom, 'nom' => $nomFamille,
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

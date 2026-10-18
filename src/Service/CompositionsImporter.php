<?php

namespace App\Service;

use App\Entity\Equipe;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compositions de match (feuilles de match) : crée les joueurs et enregistre qui a joué et combien de buts.
 *
 * CSV « ; » : Équipe ; Match ; Adversaire ; N° ; Joueur ; Buts
 * - Match : journée (« J3 » ou « 3 ») ou date (AAAA-MM-JJ) ; Adversaire (facultatif) sert à retrouver le bon match.
 * - Joueur : « PRÉNOM NOM » tel qu'écrit sur la feuille de match.
 * Les joueurs sont toujours créés ; une composition n'est rattachée que si le match existe déjà
 * (import FFHandball fait) — sinon relancer la commande après l'import. Relancer ne crée pas de doublon.
 */
class CompositionsImporter
{
    /** @var array<string, Joueur> */
    private array $joueurs = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{joueurs: int, matchs: int, participations: int, avertissements: list<string>}
     */
    public function importer(string $chemin): array
    {
        $res = ['joueurs' => 0, 'matchs' => 0, 'participations' => 0, 'avertissements' => []];
        $groupes = [];
        foreach ($this->lire($chemin) as $l) {
            $groupes[$l['equipe'].'|'.$l['match'].'|'.$l['adversaire']][] = $l;
        }

        foreach ($groupes as $lignes) {
            [$premiere] = $lignes;
            $equipe = $this->em->getRepository(Equipe::class)->findOneBy(['nom' => $premiere['equipe'], 'estAjso' => true]);
            if (!$equipe) {
                $res['avertissements'][] = "Équipe inconnue « {$premiere['equipe']} » (importez d'abord le tableau des équipes)";
                continue;
            }
            $rencontre = $this->rencontre($equipe, $premiere['match'], $premiere['adversaire']);
            $libelleMatch = $premiere['match'].($premiere['adversaire'] !== '' ? " contre {$premiere['adversaire']}" : '');
            if (!$rencontre) {
                $res['avertissements'][] = "{$equipe->getNom()} — {$libelleMatch} : match introuvable (lancez l'import FFHandball puis relancez cette commande). Joueurs créés quand même.";
            } else {
                ++$res['matchs'];
            }

            $totalButs = 0;
            foreach ($lignes as $l) {
                [$joueur, $nouveau] = $this->joueur($equipe, $l['joueur']);
                $res['joueurs'] += (int) $nouveau;
                $numero = ctype_digit($l['n']) ? (int) $l['n'] : null;
                if ($numero !== null) {
                    $joueur->setNumero($numero); // numéro du dernier match importé
                }
                $buts = ctype_digit($l['buts']) ? (int) $l['buts'] : 0;
                $totalButs += $buts;
                if (!$rencontre) {
                    continue;
                }
                $p = ($joueur->getId() ? $this->em->getRepository(Participation::class)->findOneBy(['rencontre' => $rencontre, 'joueur' => $joueur]) : null)
                    ?? (new Participation())->setRencontre($rencontre)->setJoueur($joueur);
                $this->em->persist($p->setNumero($numero)->setButs($buts));
                ++$res['participations'];
            }

            // Contrôle : total des buts = score de l'AJSO sur ce match
            if ($rencontre && $rencontre->getScoreDom() !== null) {
                $score = $rencontre->isDomicile() ? $rencontre->getScoreDom() : $rencontre->getScoreExt();
                if ($score !== $totalButs) {
                    $res['avertissements'][] = sprintf('%s — %s : %d buts dans la compo, %d au score officiel', $equipe->getNom(), $libelleMatch, $totalButs, $score);
                }
            }
        }

        return $res;
    }

    private function rencontre(Equipe $equipe, string $match, string $adversaire): ?Rencontre
    {
        $matchs = $this->em->getRepository(Rencontre::class)->findBy(['equipe' => $equipe], ['date' => 'ASC']);
        if ($adversaire !== '') {
            $voulu = self::mots($adversaire);
            $parAdv = array_values(array_filter($matchs, fn (Rencontre $r) => $voulu && array_intersect($voulu, self::mots($r->getEquipeAdverse()?->getNom())) === $voulu));
            if (count($parAdv) === 1) {
                return $parAdv[0];
            }
            if ($parAdv) {
                $matchs = $parAdv; // plusieurs rencontres face à cet adversaire : départage par la journée / date
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $match)) {
            foreach ($matchs as $r) {
                if ($r->getDate()?->format('Y-m-d') === $match) {
                    return $r;
                }
            }

            return null;
        }
        $journee = 'J'.ltrim(preg_replace('/\D/', '', $match), '0');
        foreach ($matchs as $r) {
            if ($r->getJournee() === $journee) {
                return $r;
            }
        }

        return null;
    }

    /** @return array{0: Joueur, 1: bool} joueur et « nouvellement créé » */
    private function joueur(Equipe $equipe, string $nomComplet): array
    {
        [$prenom, $nom] = self::decouper($nomComplet);

        return $this->trouverJoueur($equipe, $prenom, $nom);
    }

    /**
     * Joueur de l'équipe portant ce nom (casse et accents ignorés), créé s'il n'existe pas encore.
     *
     * @return array{0: Joueur, 1: bool} joueur et « nouvellement créé »
     */
    public function trouverJoueur(Equipe $equipe, string $prenom, string $nom): array
    {
        $cle = $equipe->getId().'|'.self::cle("$prenom $nom");
        if (isset($this->joueurs[$cle])) {
            return [$this->joueurs[$cle], false];
        }
        foreach ($this->em->getRepository(Joueur::class)->findBy(['equipe' => $equipe]) as $j) {
            if (self::cle($j->getPrenom().' '.$j->getNom()) === self::cle("$prenom $nom")) {
                return [$this->joueurs[$cle] = $j, false];
            }
        }
        $j = (new Joueur())->setEquipe($equipe)->setPrenom($prenom)->setNom($nom);
        $this->em->persist($j);

        return [$this->joueurs[$cle] = $j, true];
    }

    /** « LUCAS ROYER--CENARD » → ["Lucas", "Royer-Cenard"] ; « JULIEN LE BEUVANT » → ["Julien", "Le Beuvant"] */
    private static function decouper(string $nomComplet): array
    {
        $mots = preg_split('/\s+/u', trim(preg_replace('/-{2,}/', '-', $nomComplet)));
        $prenom = self::casse(array_shift($mots) ?? '');

        return [$prenom, self::casse(implode(' ', $mots))];
    }

    private static function casse(string $s): string
    {
        return preg_replace_callback('/(^|[\s\'-])(\p{L})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), mb_strtolower($s));
    }

    /** @return list<string> mots significatifs (« PL AVRANCHES 2 » → ["AVRANCHES"]) */
    private static function mots(?string $s): array
    {
        $mots = preg_split('/[^A-Z0-9]+/', self::cle((string) $s), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($mots, fn ($m) => strlen($m) > 2 && !in_array($m, ['HANDBALL', 'CLUB', 'ENTENTE'], true)));
    }

    private static function cle(string $s): string
    {
        $s = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $s)));

        return preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s);
    }

    /** @return list<array<string, string>> */
    private function lire(string $chemin): array
    {
        $contenu = is_readable($chemin) ? file_get_contents($chemin) : false;
        if ($contenu === false) {
            throw new \InvalidArgumentException("Fichier illisible : $chemin");
        }
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        $contenu = preg_replace('/^\x{FEFF}/u', '', $contenu);
        $lignes = preg_split('/\r?\n/', trim($contenu));
        $sep = str_contains($lignes[0], ';') ? ';' : (str_contains($lignes[0], "\t") ? "\t" : ',');
        $entete = array_map(fn ($c) => strtolower(trim(strtr(self::cle($c), ['°' => '', 'N°' => 'N']))), str_getcsv(array_shift($lignes), $sep, '"', ''));
        $entete = array_map(fn ($c) => match (true) {
            str_starts_with($c, 'equipe') => 'equipe',
            str_starts_with($c, 'match') || str_starts_with($c, 'journee') => 'match',
            str_starts_with($c, 'adversaire') => 'adversaire',
            $c === 'n' || $c === 'no' || str_starts_with($c, 'num') => 'n',
            str_starts_with($c, 'joueur') => 'joueur',
            str_starts_with($c, 'but') => 'buts',
            default => $c,
        }, $entete);
        if ($manque = array_diff(['equipe', 'match', 'joueur'], $entete)) {
            throw new \InvalidArgumentException('Colonnes manquantes : '.implode(', ', $manque));
        }
        $out = [];
        foreach ($lignes as $ligne) {
            if (trim($ligne) === '') {
                continue;
            }
            $cols = array_map('trim', str_getcsv($ligne, $sep, '"', ''));
            $l = array_combine($entete, array_pad(array_slice($cols, 0, count($entete)), count($entete), ''));
            $out[] = $l + ['adversaire' => '', 'n' => '', 'buts' => '0'];
        }

        return array_values(array_filter($out, fn ($l) => $l['joueur'] !== ''));
    }
}

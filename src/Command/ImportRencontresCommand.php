<?php

namespace App\Command;

use App\Entity\Equipe;
use App\Entity\Rencontre;
use App\Repository\EquipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import du calendrier depuis un CSV (séparateur « ; », en-tête obligatoire) :
 * equipe;adversaire;date;heure;lieu;domicile;journee
 *
 * - equipe     : nom exact d'une équipe AJSO existante (ex. « Seniors M1 », « -15 ans M Entente Côte de Nacre »)
 * - adversaire : créé automatiquement s'il n'existe pas
 * - date       : AAAA-MM-JJ, ou « WE AAAA-MM-JJ » (samedi du week-end) si le jour exact est inconnu
 * - heure      : HH:MM ou vide
 * - domicile   : oui/non
 *
 * Un match déjà présent (même équipe, même adversaire, même date) est mis à jour au lieu d'être doublé :
 * le fichier peut donc être réimporté après correction.
 */
#[AsCommand(name: 'app:import-rencontres', description: 'Importe des matchs depuis un fichier CSV')]
class ImportRencontresCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EquipeRepository $equipes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Chemin du CSV')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Vérifie le fichier sans rien enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chemin = $input->getArgument('fichier');
        $handle = is_readable($chemin) ? fopen($chemin, 'r') : false;
        if (!$handle) {
            $io->error("Fichier illisible : $chemin");

            return Command::FAILURE;
        }

        $entete = array_map(fn ($c) => strtolower(trim($c, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle, null, ';', '"', '') ?: []);
        $attendu = ['equipe', 'adversaire', 'date', 'heure', 'lieu', 'domicile', 'journee'];
        if (array_diff($attendu, $entete)) {
            $io->error('En-tête attendue : '.implode(';', $attendu));

            return Command::FAILURE;
        }

        $crees = 0;
        $majs = 0;
        $vus = [];
        $erreurs = [];
        $ligne = 1;
        $adversairesCrees = [];
        while (($cols = fgetcsv($handle, null, ';', '"', '')) !== false) {
            ++$ligne;
            if (count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_combine($entete, array_pad(array_map('trim', $cols), count($entete), ''));

            $equipe = $this->equipes->findOneBy(['nom' => $row['equipe'], 'estAjso' => true]);
            if (!$equipe) {
                $erreurs[] = "L$ligne : équipe AJSO inconnue « {$row['equipe']} »";
                continue;
            }

            $nomAdv = $row['adversaire'];
            // Adversaire de la même catégorie (« CAEN HB » seniors ≠ « CAEN HB » -13), sinon sans catégorie
            $cleAdv = $nomAdv.'|'.$equipe->getCategorie()?->getId();
            $adversaire = $adversairesCrees[$cleAdv]
                ?? $this->equipes->findOneBy(['nom' => $nomAdv, 'estAjso' => false, 'categorie' => $equipe->getCategorie()])
                ?? $this->equipes->findOneBy(['nom' => $nomAdv, 'estAjso' => false, 'categorie' => null]);
            if (!$adversaire && $nomAdv !== '') {
                $adversaire = (new Equipe())->setNom($nomAdv)->setEstAjso(false)->setCategorie($equipe->getCategorie());
                $this->em->persist($adversaire);
                $adversairesCrees[$cleAdv] = $adversaire;
            }
            if (!$adversaire) {
                $erreurs[] = "L$ligne : adversaire manquant";
                continue;
            }

            $weekEnd = (bool) preg_match('/^WE\s+/i', $row['date']);
            $date = \DateTime::createFromFormat('!Y-m-d', preg_replace('/^WE\s+/i', '', $row['date']));
            if (!$date) {
                $erreurs[] = "L$ligne : date invalide « {$row['date']} »";
                continue;
            }
            $heure = $row['heure'] !== '' ? \DateTime::createFromFormat('!H:i', $row['heure']) : null;
            if ($row['heure'] !== '' && !$heure) {
                $erreurs[] = "L$ligne : heure invalide « {$row['heure']} »";
                continue;
            }

            // Doublon (déjà en base ou plus haut dans le fichier) : mise à jour
            $cle = spl_object_id($equipe).'|'.spl_object_id($adversaire).'|'.$date->format('Y-m-d');
            $match = $vus[$cle] ?? ($adversaire->getId() ? $this->em->getRepository(Rencontre::class)->findOneBy(['equipe' => $equipe, 'equipeAdverse' => $adversaire, 'date' => $date]) : null);
            $match ? ++$majs : ++$crees;
            $this->em->persist($vus[$cle] = ($match ?? new Rencontre())
                ->setEquipe($equipe)
                ->setEquipeAdverse($adversaire)
                ->setDate($date)
                ->setDateConfirmee(!$weekEnd)
                ->setHeure($heure)
                ->setLieu($row['lieu'] !== '' ? $row['lieu'] : 'À définir')
                ->setDomicile(in_array(strtolower($row['domicile']), ['oui', 'o', '1', 'true', 'dom', 'domicile'], true))
                ->setJournee($row['journee'] !== '' ? $row['journee'] : null));
        }
        fclose($handle);

        foreach ($erreurs as $e) {
            $io->warning($e);
        }
        if ($input->getOption('dry-run')) {
            $io->note("Simulation : $crees match(s) à créer, $majs à mettre à jour, ".count($erreurs).' erreur(s). Rien enregistré.');

            return $erreurs ? Command::FAILURE : Command::SUCCESS;
        }
        $this->em->flush();
        $io->success("$crees match(s) créé(s), $majs mis à jour, ".count($adversairesCrees).' adversaire(s) créé(s).');

        return $erreurs ? Command::FAILURE : Command::SUCCESS;
    }
}

<?php

namespace App\Command;

use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Repository\EquipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\FfhbImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Met à jour matchs, scores et classements depuis ffhandball.fr.
 * À planifier une à deux fois par jour (cron / Planificateur de tâches Windows).
 */
#[AsCommand(name: 'app:ffhb:import', description: 'Importe matchs, scores et classements depuis ffhandball.fr')]
class ImportFfhbCommand extends Command
{
    private ?\Closure $apresRelecture = null;

    public function __construct(
        private readonly EquipeRepository $equipes,
        private readonly FfhbImporter $importer,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $dossierProjet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('equipe', null, InputOption::VALUE_REQUIRED, "Identifiant ou nom d'une seule équipe")
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Enregistre les pages lues dans ffhb-debug/ (diagnostic)')
            ->addOption('relire-feuilles', null, InputOption::VALUE_NONE, 'Relit les feuilles de match déjà lues (et retire les joueurs créés par une feuille qui n\'ont plus aucun match)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getOption('equipe');
        $liste = $id
            ? array_filter([ctype_digit((string) $id) ? $this->equipes->find($id) : $this->equipes->findOneBy(['nom' => $id, 'estAjso' => true])])
            : $this->equipes->findImportables();
        if ($input->getOption('debug')) {
            $dossier = $this->dossierProjet.'/ffhb-debug';
            $this->importer->activerDebug($dossier);
            $io->note("Pages enregistrées dans $dossier");
        }
        if (!$liste) {
            $io->warning('Aucune équipe à importer : renseignez « Nom FFHandball » (équipe) et « URL FFHandball » (championnat) dans l\'admin.');

            return Command::SUCCESS;
        }

        if ($input->getOption('relire-feuilles')) {
            $ids = array_map(fn ($e) => $e->getId(), $liste);
            $n = $this->em->createQuery('UPDATE '.Rencontre::class.' r SET r.feuilleLue = false WHERE r.equipe IN (:ids)')
                ->setParameter('ids', $ids)->execute();
            $io->note(sprintf('%d feuille(s) seront relues.', $n));
            $this->apresRelecture = fn () => $this->retirerOrphelins($ids, $io);
        }

        $echecs = 0;
        foreach ($liste as $equipe) {
            try {
                $r = $this->importer->importer($equipe);
                $io->writeln(sprintf('<info>✔ %s</info> : %d compétition(s), %d match(s) créé(s), %d mis à jour, classements %d lignes, %d feuille(s) de match lue(s), %d nouveau(x) joueur(s) (%d pages)',
                    $r['equipe'], $r['competitions'], $r['crees'], $r['maj'], $r['classement'], $r['feuilles'], $r['joueurs'], $r['pages']));
                foreach ($r['infos'] as $info) {
                    $io->writeln("  <info>ℹ $info</info>");
                }
                foreach ($r['ignorees'] as $raison) {
                    $io->writeln("  <comment>⚠ $raison</comment>");
                }
            } catch (\Throwable $e) {
                ++$echecs;
                $io->writeln(sprintf('<error>✘ %s</error> : %s', $equipe->getNom(), $e->getMessage()));
            }
        }

        if ($this->apresRelecture) {
            ($this->apresRelecture)();
        }

        return $echecs ? Command::FAILURE : Command::SUCCESS;
    }

    /** Après relecture : retire les joueurs créés par une feuille qui n'ont plus aucun match (fiche non complétée). */
    private function retirerOrphelins(array $ids, SymfonyStyle $io): void
    {
        $orphelins = $this->em->createQuery('SELECT j FROM '.Joueur::class.' j WHERE j.equipe IN (:ids) AND j.creeParFeuille = true AND j.poste IS NULL AND j.photo IS NULL AND j.capitaine = false
            AND NOT EXISTS (SELECT p.id FROM '.Participation::class.' p WHERE p.joueur = j)')->setParameter('ids', $ids)->getResult();
        foreach ($orphelins as $j) {
            $this->em->remove($j);
        }
        $this->em->flush();
        if ($orphelins) {
            $io->note(sprintf('%d joueur(s) sans aucun match retiré(s) : %s', count($orphelins), implode(', ', array_map(fn ($j) => $j->getNomAffiche(), $orphelins))));
        }
    }
}

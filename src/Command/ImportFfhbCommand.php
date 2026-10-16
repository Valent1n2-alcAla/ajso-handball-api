<?php

namespace App\Command;

use App\Repository\EquipeRepository;
use App\Service\FfhbImporter;
use Symfony\Component\Console\Attribute\AsCommand;
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
    public function __construct(
        private readonly EquipeRepository $equipes,
        private readonly FfhbImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('equipe', null, InputOption::VALUE_REQUIRED, "Identifiant d'une seule équipe");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getOption('equipe');
        $liste = $id ? array_filter([$this->equipes->find($id)]) : $this->equipes->findImportables();
        if (!$liste) {
            $io->warning('Aucune équipe à importer : renseignez « Nom FFHandball » (équipe) et « URL FFHandball » (championnat) dans l\'admin.');

            return Command::SUCCESS;
        }

        $echecs = 0;
        foreach ($liste as $equipe) {
            try {
                $r = $this->importer->importer($equipe);
                $io->writeln(sprintf('<info>✔ %s</info> : %d créé(s), %d mis à jour, classement %d lignes (%d pages lues)',
                    $r['equipe'], $r['crees'], $r['maj'], $r['classement'], $r['pages']));
            } catch (\Throwable $e) {
                ++$echecs;
                $io->writeln(sprintf('<error>✘ %s</error> : %s', $equipe->getNom(), $e->getMessage()));
            }
        }

        return $echecs ? Command::FAILURE : Command::SUCCESS;
    }
}

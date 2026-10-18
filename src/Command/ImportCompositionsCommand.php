<?php

namespace App\Command;

use App\Service\CompositionsImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Joueurs, compositions et buts depuis les feuilles de match (voir docs/compositions-2026-2027.csv).
 */
#[AsCommand(name: 'app:import-compositions', description: 'Importe les compositions de match (joueurs, numéros, buts)')]
class ImportCompositionsCommand extends Command
{
    public function __construct(
        private readonly CompositionsImporter $importer,
        private readonly EntityManagerInterface $em,
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
        try {
            $r = $this->importer->importer($input->getArgument('fichier'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        foreach ($r['avertissements'] as $a) {
            $io->warning($a);
        }
        $bilan = sprintf('%d nouveau(x) joueur(s), %d match(s) rattaché(s), %d ligne(s) de composition', $r['joueurs'], $r['matchs'], $r['participations']);
        if ($input->getOption('dry-run')) {
            $io->note("Simulation : $bilan. Rien enregistré.");

            return Command::SUCCESS;
        }
        $this->em->flush();
        $io->success($bilan.'.');

        return Command::SUCCESS;
    }
}

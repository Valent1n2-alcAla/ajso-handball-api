<?php

namespace App\Command;

use App\Service\EquipesImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée ou met à jour les équipes de la saison depuis le tableau (voir docs/equipes-2026-2027.csv).
 */
#[AsCommand(name: 'app:import-equipes', description: 'Crée ou met à jour équipes, compétitions et créneaux depuis le tableau de saison (CSV)')]
class ImportEquipesCommand extends Command
{
    public function __construct(
        private readonly EquipesImporter $importer,
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
        $io->listing($r['equipes']);
        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Simulation : %d équipes, %d créneaux. Rien enregistré.', count($r['equipes']), $r['creneaux']));

            return Command::SUCCESS;
        }
        $this->em->flush();
        $io->success(sprintf('%d équipes et %d créneaux enregistrés.', count($r['equipes']), $r['creneaux']));

        return Command::SUCCESS;
    }
}

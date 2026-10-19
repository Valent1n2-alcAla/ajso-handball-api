<?php

namespace App\Command;

use App\Entity\MessageContact;
use App\Entity\Preinscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RGPD : supprime les messages du formulaire de contact et les préinscriptions plus anciens que la durée de conservation.
 * À planifier (ex. une fois par mois).
 */
#[AsCommand(name: 'app:messages:purger', description: 'Supprime les messages de contact et préinscriptions plus anciens que N mois (RGPD)')]
class PurgerMessagesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mois', null, InputOption::VALUE_REQUIRED, 'Durée de conservation en mois', '12')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les messages concernés sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mois = $input->getOption('mois');
        if (!ctype_digit((string) $mois) || (int) $mois < 1) {
            $io->error('--mois doit être un entier positif.');

            return Command::INVALID;
        }
        $limite = new \DateTimeImmutable(sprintf('-%d months', $mois));

        $types = [MessageContact::class => 'message(s)', Preinscription::class => 'préinscription(s)'];

        if ($input->getOption('dry-run')) {
            foreach ($types as $classe => $libelle) {
                $n = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.$classe.' m WHERE m.createdAt < :limite')
                    ->setParameter('limite', $limite)->getSingleScalarResult();
                $io->note(sprintf('%d %s antérieur(s) au %s seraient supprimés.', $n, $libelle, $limite->format('d/m/Y')));
            }

            return Command::SUCCESS;
        }

        foreach ($types as $classe => $libelle) {
            $n = $this->em->createQuery('DELETE FROM '.$classe.' m WHERE m.createdAt < :limite')
                ->setParameter('limite', $limite)->execute();
            $io->success(sprintf('%d %s antérieur(s) au %s supprimé(s).', $n, $libelle, $limite->format('d/m/Y')));
        }

        return Command::SUCCESS;
    }
}

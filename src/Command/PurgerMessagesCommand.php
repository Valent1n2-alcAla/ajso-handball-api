<?php

namespace App\Command;

use App\Entity\MessageContact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RGPD : supprime les messages du formulaire de contact plus anciens que la durée de conservation.
 * À planifier (ex. une fois par mois).
 */
#[AsCommand(name: 'app:messages:purger', description: 'Supprime les messages de contact plus anciens que N mois (RGPD)')]
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

        if ($input->getOption('dry-run')) {
            $n = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.MessageContact::class.' m WHERE m.createdAt < :limite')
                ->setParameter('limite', $limite)->getSingleScalarResult();
            $io->note(sprintf('%d message(s) antérieur(s) au %s seraient supprimés.', $n, $limite->format('d/m/Y')));

            return Command::SUCCESS;
        }

        $n = $this->em->createQuery('DELETE FROM '.MessageContact::class.' m WHERE m.createdAt < :limite')
            ->setParameter('limite', $limite)->execute();
        $io->success(sprintf('%d message(s) antérieur(s) au %s supprimé(s).', $n, $limite->format('d/m/Y')));

        return Command::SUCCESS;
    }
}

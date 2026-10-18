<?php

namespace App\Command;

use App\Entity\Equipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** État de l'import par équipe : matchs, feuilles de match, joueurs, statistiques. */
#[AsCommand(name: 'app:ffhb:etat', description: "Affiche l'état des matchs, feuilles de match et effectifs par équipe")]
class EtatFfhbCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sql = <<<SQL
            SELECT e.nom,
              (SELECT COUNT(*) FROM rencontre r WHERE r.equipe_id = e.id) AS matchs,
              (SELECT COUNT(*) FROM rencontre r WHERE r.equipe_id = e.id AND r.score_dom IS NOT NULL) AS joues,
              (SELECT COUNT(*) FROM rencontre r WHERE r.equipe_id = e.id AND r.score_dom IS NOT NULL AND r.fdm_code IS NOT NULL) AS avec_code,
              (SELECT COUNT(*) FROM rencontre r WHERE r.equipe_id = e.id AND r.feuille_lue = true) AS feuilles,
              (SELECT COUNT(*) FROM joueur j WHERE j.equipe_id = e.id) AS joueurs,
              (SELECT COUNT(*) FROM participation p JOIN joueur j ON j.id = p.joueur_id WHERE j.equipe_id = e.id) AS lignes_stats
            FROM equipe e WHERE e.est_ajso = true ORDER BY e.ordre
            SQL;
        $lignes = $this->em->getConnection()->fetchAllNumeric($sql);
        $io->table(['Équipe', 'Matchs', 'Joués', 'Joués avec code feuille', 'Feuilles lues', 'Joueurs', 'Lignes de stats'], $lignes);
        $io->text([
            '« Joués avec code feuille » < « Joués » : la FFHB n\'a pas (encore) publié certaines feuilles.',
            '« Feuilles lues » < « Joués avec code feuille » : relancer app:ffhb:import (25 feuilles max par passage).',
        ]);

        return Command::SUCCESS;
    }
}

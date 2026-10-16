<?php

namespace App\Repository;

use App\Entity\Equipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Equipe>
 */
class EquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipe::class);
    }

    /**
     * Équipes du club configurées pour l'import FFHandball.
     *
     * @return list<Equipe>
     */
    public function findImportables(): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.championnat', 'c')
            ->where('e.estAjso = true')
            ->andWhere("e.nomFfhb IS NOT NULL AND e.nomFfhb <> ''")
            ->andWhere("c.urlFfhb IS NOT NULL AND c.urlFfhb <> ''")
            ->orderBy('e.ordre', 'ASC')
            ->getQuery()->getResult();
    }
}

<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Convocation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Convocations : le public ne voit que celles publiées (un brouillon répond 404) ; l'admin voit tout.
 */
final class ConvocationsPublieesExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $securite)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->filtrer($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->filtrer($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    private function filtrer(QueryBuilder $qb, QueryNameGeneratorInterface $noms, string $classe): void
    {
        if ($classe !== Convocation::class || $this->securite->isGranted('ROLE_ADMIN')) {
            return;
        }
        $parametre = $noms->generateParameterName('publiee');
        $qb->andWhere(sprintf('%s.publiee = :%s', $qb->getRootAliases()[0], $parametre))
            ->setParameter($parametre, true);
    }
}

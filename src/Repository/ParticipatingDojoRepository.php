<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dojo;
use App\Entity\ParticipatingDojo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParticipatingDojo>
 */
class ParticipatingDojoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipatingDojo::class);
    }

    /**
     * Un club engagé dans au moins un taikai ne peut pas être supprimé du
     * référentiel : c'est le `dependent: :restrict_with_error` de Rails.
     */
    public function existsForDojo(Dojo $dojo): bool
    {
        $count = $this->createQueryBuilder('pd')
            ->select('COUNT(pd.id)')
            ->andWhere('pd.dojo = :dojo')
            ->setParameter('dojo', $dojo)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}

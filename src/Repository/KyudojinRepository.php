<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Kyudojin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Kyudojin>
 */
class KyudojinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Kyudojin::class);
    }

    /**
     * Recherche par nom ou prénom, pour l'autocomplétion des licenciés
     * (`Kyudojin.containing` côté Rails).
     *
     * @return list<Kyudojin>
     */
    public function search(string $query, int $limit = 15): array
    {
        if ('' === trim($query)) {
            return [];
        }

        /** @var list<Kyudojin> $result */
        $result = $this->createQueryBuilder('k')
            ->andWhere('LOWER(k.lastname) LIKE :q OR LOWER(k.firstname) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower(trim($query)).'%')
            ->orderBy('k.lastname', 'ASC')
            ->addOrderBy('k.firstname', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }
}

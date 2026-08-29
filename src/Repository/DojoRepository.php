<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dojo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dojo>
 */
class DojoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dojo::class);
    }

    /** Requête de la liste des clubs, triée par nom court comme côté Rails. */
    public function createListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.shortname', 'ASC');
    }

    /**
     * Recherche par nom court, nom entier ou pays, pour l'autocomplétion des
     * clubs hôtes (`Dojo.containing` côté Rails).
     *
     * @return list<Dojo>
     */
    public function search(string $query, int $limit = 20): array
    {
        if ('' === trim($query)) {
            return [];
        }

        /** @var list<Dojo> $result */
        $result = $this->createQueryBuilder('d')
            ->andWhere('LOWER(d.shortname) LIKE :q OR LOWER(d.name) LIKE :q OR LOWER(d.countryCode) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower(trim($query)).'%')
            ->orderBy('d.shortname', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }
}

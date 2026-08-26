<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Taikai;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Taikai>
 */
class TaikaiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Taikai::class);
    }

    /** Requête de la liste des taikai, la plus récente en tête. */
    public function createListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.startDate', 'DESC')
            ->addOrderBy('t.shortname', 'ASC');
    }

    public function findOneByShortname(string $shortname): ?Taikai
    {
        return $this->findOneBy(['shortname' => $shortname]);
    }

    /**
     * Charge un taikai avec le graphe nécessaire aux écrans de marquage et de
     * classement, pour éviter les requêtes N+1.
     */
    public function findWithFullGraph(int $id): ?Taikai
    {
        /** @var Taikai|null $taikai */
        $taikai = $this->createQueryBuilder('t')
            ->addSelect('pd', 'p', 's', 'r', 'team')
            ->leftJoin('t.participatingDojos', 'pd')
            ->leftJoin('pd.participants', 'p')
            ->leftJoin('p.scores', 's')
            ->leftJoin('s.results', 'r')
            ->leftJoin('pd.teams', 'team')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $taikai;
    }

    /**
     * Taikai terminés d'une année donnée, base du classement du championnat.
     *
     * @return list<Taikai>
     */
    public function findFinishedForYear(int $year): array
    {
        /** @var list<Taikai> $result */
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.startDate >= :from')
            ->andWhere('t.startDate <= :to')
            ->setParameter('from', new \DateTimeImmutable($year.'-01-01'))
            ->setParameter('to', new \DateTimeImmutable($year.'-12-31'))
            ->orderBy('t.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Années pour lesquelles au moins un taikai existe, les plus récentes en tête.
     *
     * @return list<int>
     */
    public function findChampionshipYears(): array
    {
        // Requête native : DQL n'expose pas d'extraction d'année portable.
        $sql = 'SELECT DISTINCT EXTRACT(YEAR FROM start_date)::int AS year
                FROM taikais
                WHERE start_date IS NOT NULL
                ORDER BY year DESC';

        $years = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql)
            ->fetchFirstColumn();

        return array_map(intval(...), $years);
    }
}

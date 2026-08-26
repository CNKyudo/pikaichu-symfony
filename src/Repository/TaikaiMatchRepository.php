<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaikaiMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaikaiMatch>
 */
class TaikaiMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaikaiMatch::class);
    }
}

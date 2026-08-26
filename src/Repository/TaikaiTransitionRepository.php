<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaikaiTransition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaikaiTransition>
 */
class TaikaiTransitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaikaiTransition::class);
    }
}

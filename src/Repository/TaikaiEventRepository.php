<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaikaiEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaikaiEvent>
 */
class TaikaiEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaikaiEvent::class);
    }
}

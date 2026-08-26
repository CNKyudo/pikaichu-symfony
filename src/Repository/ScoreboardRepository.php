<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Scoreboard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Scoreboard>
 */
class ScoreboardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scoreboard::class);
    }

    public function findOneByApiKey(string $apiKey): ?Scoreboard
    {
        return $this->findOneBy(['apiKey' => $apiKey]);
    }
}

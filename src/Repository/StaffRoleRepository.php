<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StaffRole;
use App\Enum\StaffRoleCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffRole>
 */
class StaffRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffRole::class);
    }

    public function findOneByCode(StaffRoleCode $code): ?StaffRole
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** Le rôle demandé, en échouant explicitement s'il manque en base (seed absent). */
    public function getByCode(StaffRoleCode $code): StaffRole
    {
        return $this->findOneByCode($code)
            ?? throw new \RuntimeException(\sprintf('Staff role "%s" is missing, did you load the fixtures?', $code->value));
    }
}

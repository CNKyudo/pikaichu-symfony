<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findOneByEmailAddress(string $emailAddress): ?User
    {
        return $this->findOneBy(['emailAddress' => mb_strtolower(trim($emailAddress))]);
    }

    /**
     * Recherche par nom, prénom ou email, pour l'autocomplétion du staff.
     *
     * @return list<User>
     */
    public function search(string $query, int $limit = 20): array
    {
        if ('' === trim($query)) {
            return [];
        }

        /** @var list<User> $result */
        $result = $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.firstname) LIKE :q OR LOWER(u.lastname) LIKE :q OR LOWER(u.emailAddress) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower(trim($query)).'%')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }
}

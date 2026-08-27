<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Vide la base entre deux tests.
 *
 * Point unique où déclarer les tables : en ajoutant une entité, complétez la
 * liste ici et tous les tests en bénéficient.
 */
trait DatabaseResetTrait
{
    /**
     * Tables purgées, dans un ordre sans importance : `CASCADE` lève les
     * dépendances et `RESTART IDENTITY` remet les séquences à zéro.
     *
     * @var list<string>
     */
    private const array RESETTABLE_TABLES = [
        'results', 'scores', 'tachis', 'matches', 'participants', 'teams',
        'scoreboards', 'staffs', 'participating_dojos', 'taikai_events',
        'taikai_transitions', 'taikais', 'staff_roles', 'dojos', 'kyudojins',
        'sessions', 'users',
    ];

    private function resetDatabase(EntityManagerInterface $entityManager): void
    {
        $entityManager->getConnection()->executeStatement(
            'TRUNCATE TABLE '.implode(', ', self::RESETTABLE_TABLES).' RESTART IDENTITY CASCADE',
        );
    }

    /**
     * À appeler entre la préparation du jeu de données et la première requête.
     *
     * Sans cela, la requête réutilise les entités que le test vient de
     * construire : leurs collections `OneToMany`, créées vides par le
     * constructeur, passent pour déjà chargées et masquent les relations
     * réellement écrites en base. Un test peut alors passer — ou échouer — pour
     * de mauvaises raisons.
     */
    private function commitSeeding(EntityManagerInterface $entityManager): void
    {
        $entityManager->flush();
        $entityManager->clear();
    }
}

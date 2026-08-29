<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

/**
 * L'isolation entre deux tests est assurée par `dama/doctrine-test-bundle`
 * (transaction annulée à la fin de chaque test) — voir `phpunit.xml.dist` et
 * `config/packages/test/dama_doctrine_test.yaml`. Ce trait ne porte donc plus
 * que `commitSeeding()`.
 */
trait DatabaseResetTrait
{
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

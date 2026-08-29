<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Security\Voter\ParticipatingDojoVoter;

/**
 * Vérifications d'appartenance hiérarchique parent/enfant partagées par les
 * contrôleurs dont les routes imbriquent taikai/club hôte/entité : bloquent
 * une URL forgée qui référencerait un enfant appartenant à un autre parent.
 */
trait AssertsEntityOwnershipTrait
{
    private function assertBelongsTo(?object $actualParent, object $expectedParent, string $message): void
    {
        if ($actualParent !== $expectedParent) {
            throw $this->createNotFoundException($message);
        }
    }

    /** Club hôte du taikai visé, avec droit d'édition. */
    private function assertHostClub(Taikai $taikai, ParticipatingDojo $participatingDojo): void
    {
        $this->assertBelongsTo($participatingDojo->getTaikai(), $taikai, 'Participating dojo does not belong to this taikai');

        $this->denyAccessUnlessGranted(ParticipatingDojoVoter::EDIT, $participatingDojo);
    }
}

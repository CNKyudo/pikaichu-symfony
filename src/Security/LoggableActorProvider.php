<?php

declare(strict_types=1);

namespace App\Security;

use Gedmo\Tool\ActorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Fournit l'utilisateur courant à `LoggableListener`, pour peupler la colonne
 * `username` de la piste d'audit — l'équivalent de `Audited.audited_user`.
 */
final readonly class LoggableActorProvider implements ActorProviderInterface
{
    public function __construct(private Security $security)
    {
    }

    public function getActor(): ?object
    {
        return $this->security->getUser();
    }
}

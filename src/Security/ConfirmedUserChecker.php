<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion tant que l'adresse courriel n'est pas confirmée.
 *
 * Voir `EmailConfirmationService` : cette contrainte n'existe pas côté Rails
 * (comptes auto-confirmés), c'est le pendant nécessaire du portage de la
 * confirmation — sans elle, le jeton envoyé par courriel n'aurait aucun effet.
 */
final class ConfirmedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isConfirmed()) {
            throw new CustomUserMessageAccountStatusException('email_confirmation.unconfirmed');
        }
    }

    public function checkPostAuth(UserInterface $user, ?\Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token = null): void
    {
    }
}

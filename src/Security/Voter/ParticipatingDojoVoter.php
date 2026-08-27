<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ParticipatingDojo;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un club hôte, transcrits de `ParticipatingDojoPolicy`.
 *
 * La suppression reste réservée aux administrateurs du taikai, alors que la
 * modification est également ouverte à l'administrateur du club hôte concerné.
 *
 * @extends Voter<string, ParticipatingDojo>
 */
final class ParticipatingDojoVoter extends Voter
{
    /** Modifier le club hôte. */
    public const string EDIT = 'PARTICIPATING_DOJO_EDIT';

    /** Retirer le club hôte du taikai. */
    public const string DELETE = 'PARTICIPATING_DOJO_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true)
            && $subject instanceof ParticipatingDojo;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return match ($attribute) {
            self::DELETE => $this->isTaikaiAdmin($subject, $user),
            self::EDIT => $this->isTaikaiAdmin($subject, $user) || $this->isDojoAdmin($subject, $user),
            default => false,
        };
    }

    private function isTaikaiAdmin(ParticipatingDojo $participatingDojo, User $user): bool
    {
        return $participatingDojo->getTaikai()?->hasRole($user, StaffRoleCode::TaikaiAdmin) ?? false;
    }

    /** Administrateur de ce club hôte précis, pas d'un autre du même taikai. */
    private function isDojoAdmin(ParticipatingDojo $participatingDojo, User $user): bool
    {
        foreach ($participatingDojo->getStaffs() as $staff) {
            if ($staff->getUser() === $user && StaffRoleCode::DojoAdmin === $staff->getRole()?->getCode()) {
                return true;
            }
        }

        return false;
    }
}

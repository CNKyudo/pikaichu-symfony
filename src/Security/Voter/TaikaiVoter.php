<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Taikai;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiState;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un taikai.
 *
 * Un administrateur de l'application peut tout faire. Sinon les droits découlent
 * du rôle tenu dans le staff du taikai concerné.
 *
 * @extends Voter<string, Taikai>
 */
final class TaikaiVoter extends Voter
{
    /** Modifier la configuration du taikai et faire avancer les étapes. */
    public const string EDIT = 'TAIKAI_EDIT';

    /** Saisir les marques. */
    public const string MARK = 'TAIKAI_MARK';

    /** Rectifier une marque déjà validée. */
    public const string RECTIFY = 'TAIKAI_RECTIFY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::MARK, self::RECTIFY], true)
            && $subject instanceof Taikai;
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
            self::EDIT => $this->canEdit($subject, $user),
            self::MARK => $this->canMark($subject, $user),
            self::RECTIFY => $this->canRectify($subject, $user),
            default => false,
        };
    }

    /** Réservé aux administrateurs du taikai. */
    private function canEdit(Taikai $taikai, User $user): bool
    {
        return $taikai->hasRole($user, StaffRoleCode::TaikaiAdmin);
    }

    /**
     * La saisie est ouverte aux administrateurs et aux enregistreurs,
     * uniquement pendant l'étape « Marquage ».
     */
    private function canMark(Taikai $taikai, User $user): bool
    {
        if (!$taikai->isState(TaikaiState::Marking)) {
            return false;
        }

        return $taikai->hasRole(
            $user,
            StaffRoleCode::TaikaiAdmin,
            StaffRoleCode::DojoAdmin,
            StaffRoleCode::MarkingReferee,
        );
    }

    /**
     * La rectification reste possible tant que le marquage n'est pas clos,
     * et relève du directeur de tournoi ou du juge de cible.
     */
    private function canRectify(Taikai $taikai, User $user): bool
    {
        if (!$taikai->isState(TaikaiState::Marking)) {
            return false;
        }

        return $taikai->hasRole(
            $user,
            StaffRoleCode::TaikaiAdmin,
            StaffRoleCode::Chairman,
            StaffRoleCode::TargetReferee,
        );
    }
}

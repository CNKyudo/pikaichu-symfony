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

    /** Ajuster les rangs à l'entrée du tie-break. */
    public const string TIE_BREAK = 'TAIKAI_TIE_BREAK';

    /** Consulter le classement. Reprend `TaikaiPolicy#leaderboard_show?`. */
    public const string LEADERBOARD = 'TAIKAI_LEADERBOARD';

    /** Exporter les résultats en Excel. Reprend `TaikaiPolicy#export?`. */
    public const string EXPORT = 'TAIKAI_EXPORT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::MARK, self::RECTIFY, self::TIE_BREAK, self::LEADERBOARD, self::EXPORT], true)
            && $subject instanceof Taikai;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Les deux seules permissions qui ne dépendent pas du rôle tenu dans le staff.
        if (self::LEADERBOARD === $attribute) {
            return !$subject->isState(TaikaiState::New, TaikaiState::Registration);
        }

        if (self::EXPORT === $attribute) {
            return $subject->isState(TaikaiState::Done);
        }

        // L'étape requise s'applique à tout le monde, y compris aux administrateurs :
        // seule la condition de rôle qui suit peut être court-circuitée pour eux.
        // Reprend `TaikaiPolicy#marking_update?`/`#rectification_update?`/`#tie_break_update?`,
        // où `taikai.in_state?(...)` est un ET, jamais contourné par `user.admin?`.
        if (self::MARK === $attribute && !$subject->isState(TaikaiState::Marking)) {
            return false;
        }

        if (self::RECTIFY === $attribute && !$subject->isState(TaikaiState::Marking)) {
            return false;
        }

        if (self::TIE_BREAK === $attribute && !$subject->isState(TaikaiState::TieBreak)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return match ($attribute) {
            self::EDIT => $this->canEdit($subject, $user),
            self::MARK => $this->canMark($subject, $user),
            self::RECTIFY => $this->canRectify($subject, $user),
            self::TIE_BREAK => $this->canTieBreak($subject, $user),
            default => false,
        };
    }

    /** Réservé aux administrateurs du taikai. */
    private function canEdit(Taikai $taikai, User $user): bool
    {
        return $taikai->hasRole($user, StaffRoleCode::TaikaiAdmin);
    }

    /**
     * La saisie est ouverte aux administrateurs, enregistreurs et juges de cible.
     * Reprend `MARKING_ROLES` de `TaikaiPolicy` (`taikai_admin`, `dojo_admin`,
     * `marking_referee`, `target_referee`) ; l'étape « Marquage » est déjà vérifiée
     * par l'appelant.
     */
    private function canMark(Taikai $taikai, User $user): bool
    {
        return $taikai->hasRole(
            $user,
            StaffRoleCode::TaikaiAdmin,
            StaffRoleCode::DojoAdmin,
            StaffRoleCode::MarkingReferee,
            StaffRoleCode::TargetReferee,
        );
    }

    /**
     * Réservée aux administrateurs du taikai. Reprend `ADMIN_ROLES` de
     * `TaikaiPolicy#rectification_update?` — volontairement plus restreint que
     * la saisie elle-même. L'étape « Marquage » est déjà vérifiée par l'appelant.
     */
    private function canRectify(Taikai $taikai, User $user): bool
    {
        return $taikai->hasRole($user, StaffRoleCode::TaikaiAdmin);
    }

    /**
     * L'ajustement manuel des rangs est ouvert aux mêmes rôles que la saisie
     * (`MARKING_ROLES` de `TaikaiPolicy#tie_break_update?`) ; l'étape « Tie-Break »
     * est déjà vérifiée par l'appelant.
     */
    private function canTieBreak(Taikai $taikai, User $user): bool
    {
        return $taikai->hasRole(
            $user,
            StaffRoleCode::TaikaiAdmin,
            StaffRoleCode::DojoAdmin,
            StaffRoleCode::MarkingReferee,
            StaffRoleCode::TargetReferee,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Taikai;
use App\Entity\TaikaiEvent;
use App\Entity\TaikaiTransition;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiState;
use App\Exception\TransitionNotAllowedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Machine à états du taikai.
 *
 * Transcription directe de `TaikaiStateMachine` (Statesman) de l'application Rails :
 * mêmes transitions, mêmes gardes, mêmes effets de bord. L'état courant est porté
 * par la transition marquée `most_recent`.
 */
final readonly class TaikaiStateMachine
{
    /**
     * Transitions autorisées, en avant comme en arrière.
     *
     * @var array<string, list<TaikaiState>>
     */
    private const array TRANSITIONS = [
        'new' => [TaikaiState::Registration],
        'registration' => [TaikaiState::Marking, TaikaiState::New],
        'marking' => [TaikaiState::TieBreak, TaikaiState::Registration],
        'tie_break' => [TaikaiState::Done, TaikaiState::Marking],
        'done' => [TaikaiState::TieBreak],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScoreInitializer $scoreInitializer,
        private LeaderboardService $leaderboardService,
    ) {
    }

    /**
     * États atteignables depuis l'état courant, gardes comprises.
     *
     * @return list<TaikaiState>
     */
    public function getAllowedTransitions(Taikai $taikai): array
    {
        $current = $taikai->getCurrentState();

        return array_values(array_filter(
            self::TRANSITIONS[$current->value],
            fn (TaikaiState $to): bool => null === $this->guard($taikai, $current, $to),
        ));
    }

    public function canTransitionTo(Taikai $taikai, TaikaiState $to): bool
    {
        $current = $taikai->getCurrentState();

        return \in_array($to, self::TRANSITIONS[$current->value], true)
            && null === $this->guard($taikai, $current, $to);
    }

    /**
     * Raison du refus d'une transition, ou null si elle est permise.
     * Retourne une clé de traduction, affichable telle quelle par le contrôleur.
     */
    public function getBlockingReason(Taikai $taikai, TaikaiState $to): ?string
    {
        $current = $taikai->getCurrentState();

        if (!\in_array($to, self::TRANSITIONS[$current->value], true)) {
            return 'taikai.transition.not_allowed';
        }

        return $this->guard($taikai, $current, $to);
    }

    /**
     * Effectue la transition, ou échoue si elle n'est pas permise.
     *
     * @throws TransitionNotAllowedException
     */
    public function transitionTo(Taikai $taikai, TaikaiState $to, User $user): void
    {
        $from = $taikai->getCurrentState();

        $reason = $this->getBlockingReason($taikai, $to);
        if (null !== $reason) {
            throw new TransitionNotAllowedException($from, $to, $reason);
        }

        // Effets de bord, joués avant l'enregistrement de la transition pour
        // rester fidèle aux callbacks `before_transition` de Statesman.
        $this->runBeforeTransition($taikai, $from, $to);

        $taikai->addEvent(TaikaiEvent::stateTransition($taikai, $user, $from, $to));
        $this->recordTransition($taikai, $to);

        $this->entityManager->flush();
    }

    /**
     * Gardes conditionnant les transitions avant. Retourne une clé de traduction
     * décrivant le blocage, ou null si la voie est libre.
     */
    private function guard(Taikai $taikai, TaikaiState $from, TaikaiState $to): ?string
    {
        // Pour passer au marquage, le staff obligatoire doit être au complet
        // et tous les clubs hôtes doivent avoir fait leur tirage au sort.
        if (TaikaiState::Registration === $from && TaikaiState::Marking === $to) {
            if (!$this->hasRequiredStaff($taikai)) {
                return 'taikai.transition.missing_required_staff';
            }

            foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
                if (!$participatingDojo->isDrawn()) {
                    return 'taikai.transition.draw_not_done';
                }
            }

            return null;
        }

        // Pour passer au tie-break, toutes les flèches doivent être validées.
        if (TaikaiState::Marking === $from && TaikaiState::TieBreak === $to) {
            return $taikai->isFinalized() ? null : 'taikai.transition.results_not_finalized';
        }

        return null;
    }

    /**
     * Le staff comporte-t-il un directeur de tournoi, un juge de shajo
     * et un juge de cible ?
     */
    private function hasRequiredStaff(Taikai $taikai): bool
    {
        $present = [];
        foreach ($taikai->getStaffs() as $staff) {
            $code = $staff->getRole()?->getCode();
            if (null !== $code) {
                $present[$code->value] = true;
            }
        }

        return array_all(StaffRoleCode::requiredForMarking(), fn (StaffRoleCode $required): bool => isset($present[$required->value]));
    }

    /**
     * Effets de bord attachés à chaque transition.
     */
    private function runBeforeTransition(Taikai $taikai, TaikaiState $from, TaikaiState $to): void
    {
        match (true) {
            // Entrée en marquage : on matérialise tachis, scores et flèches.
            TaikaiState::Registration === $from && TaikaiState::Marking === $to => $this->scoreInitializer->initialize($taikai),

            // Retour à l'enregistrement : on démonte tout ce qui précède.
            TaikaiState::Marking === $from && TaikaiState::Registration === $to => $this->scoreInitializer->reset($taikai),

            // Entrée en tie-break : on fige le classement pour pouvoir l'ajuster.
            TaikaiState::Marking === $from && TaikaiState::TieBreak === $to => $this->leaderboardService->computeIntermediateRanks($taikai),

            // Retour au marquage : les rangs figés n'ont plus lieu d'être.
            TaikaiState::TieBreak === $from && TaikaiState::Marking === $to => $this->leaderboardService->clearRanks($taikai),

            default => null,
        };
    }

    /**
     * Enregistre la nouvelle transition et déplace le drapeau `most_recent`.
     */
    private function recordTransition(Taikai $taikai, TaikaiState $to): void
    {
        $sortKey = 0;
        $hadCurrent = false;
        foreach ($taikai->getTransitions() as $transition) {
            if ($transition->isMostRecent()) {
                $transition->setMostRecent(false);
                $hadCurrent = true;
            }

            $sortKey = max($sortKey, $transition->getSortKey() + 10);
        }

        // Doctrine émet les INSERT avant les UPDATE : sans ce flush intermédiaire,
        // la nouvelle transition arriverait alors que l'ancienne porte encore le
        // drapeau, ce qui violerait l'index unique partiel `most_recent`.
        if ($hadCurrent) {
            $this->entityManager->flush();
        }

        $transition = new TaikaiTransition();
        $transition->setTaikai($taikai)
            ->setToState($to)
            ->setSortKey($sortKey)
            ->setMostRecent(true);

        $taikai->addTransition($transition);
        $this->entityManager->persist($transition);
    }
}

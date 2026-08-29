<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Result;
use App\Entity\Score;
use App\Entity\Tachi;
use App\Entity\TaikaiEvent;
use App\Entity\TaikaiMatch;
use App\Entity\User;
use App\Enum\ResultStatus;
use App\Exception\MarkingException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saisie et validation des marques.
 *
 * Reprend `Score#add_result`, `Score#finalize_round` et `ParticipatingDojo#update_tachi`
 * de l'application Rails. Toute écriture passe par ce service afin que les compteurs
 * de score et l'avancement des tachis restent cohérents.
 */
final readonly class MarkingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Marque la prochaine flèche non saisie du participant.
     *
     * @throws MarkingException si tout est déjà saisi ou si la série précédente
     *                          n'a pas été validée
     */
    public function addResult(
        Participant $participant,
        ResultStatus $status,
        ?int $value = null,
        ?TaikaiMatch $match = null,
    ): Result {
        $score = $participant->getScore($match)
            ?? throw MarkingException::noEmptyResult();

        $result = $score->getFirstEmptyResult()
            ?? throw MarkingException::noEmptyResult();

        if (!$score->isPreviousRoundFinalized($result)) {
            throw MarkingException::previousRoundNotValidated(max(1, ($result->getRound() ?? 1) - 1));
        }

        $result->setStatus($status);
        if (null !== $value) {
            $result->setValue($value);
        }

        $this->recalculate($score);
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Fait tourner le statut d'une flèche déjà marquée mais pas encore validée.
     * Sert aux corrections à la volée pendant le tir.
     *
     * @throws MarkingException si la flèche est déjà validée
     */
    public function rotateResult(Result $result): Result
    {
        if ($result->isFinal()) {
            throw MarkingException::alreadyFinalized();
        }

        $score = $result->getScore();
        if (null === $score) {
            throw MarkingException::noEmptyResult();
        }

        // « Incertain » ne fait partie du cycle que tant qu'il reste des flèches à tirer.
        $allMarked = null === $score->getFirstEmptyResult();
        $result->rotateStatus($allMarked);

        $this->recalculate($score);
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Valide une série : les flèches deviennent définitives et le tachi avance.
     * C'est la « coche bleue » de la feuille de marque.
     */
    public function finalizeRound(Participant $participant, int $round, ?TaikaiMatch $match = null): void
    {
        $score = $participant->getScore($match);
        if (null === $score) {
            return;
        }

        foreach ($score->getResultsForRound($round) as $result) {
            $result->setFinal(true);
        }

        $this->recalculate($score);

        // Hors tournoi à matchs, la validation fait progresser le tachi du club hôte.
        if (null === $match) {
            $participatingDojo = $participant->getParticipatingDojo();
            if (null !== $participatingDojo) {
                $this->updateTachi($participatingDojo, $participant, $round, $match);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Modifie une flèche depuis l'écran de rectification, y compris si elle est
     * déjà validée. La flèche est alors marquée comme forcée.
     */
    public function rectify(Result $result, ResultStatus $status, ?int $value, User $user): bool
    {
        $previousStatus = $result->getStatus() ?? ResultStatus::Unknown;
        $previousValue = $result->getValue();

        $changed = $result->overrideStatus($status);
        if (null !== $value) {
            $changed = $result->overrideValue($value) || $changed;
        }

        if ($changed) {
            $score = $result->getScore();
            if (null !== $score) {
                $this->recalculate($score);
            }

            $taikai = $result->getTaikai();
            if (null !== $taikai) {
                $taikai->addEvent(TaikaiEvent::rectification($taikai, $user, $result, $previousStatus, $previousValue));
            }

            $this->entityManager->flush();
        }

        return $changed;
    }

    /**
     * Recalcule le score du participant puis, le cas échéant, celui de son équipe.
     */
    private function recalculate(Score $score): void
    {
        $score->recalculateFromResults();

        $teamScore = $score->getParticipant()?->getTeam()?->getScore($score->getMatch());
        $teamScore?->recalculateFromTeam();
    }

    /**
     * Marque un tachi comme terminé dès que tous ses archers ont validé la série.
     */
    private function updateTachi(
        ParticipatingDojo $participatingDojo,
        Participant $participant,
        int $round,
        ?TaikaiMatch $match,
    ): void {
        $tachi = $this->findTachi($participatingDojo, $participant, $round);
        if (null === $tachi) {
            return;
        }

        foreach ($tachi->getParticipants() as $member) {
            if (!($member->getScore($match)?->isRoundFinalized($tachi->getRound()) ?? false)) {
                return;
            }
        }

        $tachi->setFinished(true);
    }

    /**
     * Retrouve le tachi d'un participant pour une série donnée, en cherchant dans
     * quel groupe de tir il se trouve.
     */
    private function findTachi(ParticipatingDojo $participatingDojo, Participant $participant, int $round): ?Tachi
    {
        foreach ($participatingDojo->getTachiGroups() as $groupIndex => $group) {
            if (!\in_array($participant, $group, true)) {
                continue;
            }

            foreach ($participatingDojo->getTachis() as $tachi) {
                if ($tachi->getRound() === $round && $tachi->getIndex() === $groupIndex + 1) {
                    return $tachi;
                }
            }
        }

        return null;
    }
}

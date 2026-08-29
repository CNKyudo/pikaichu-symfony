<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RankedGroup;
use App\Entity\Participant;
use App\Entity\Taikai;
use App\Entity\TaikaiEvent;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ajustement manuel des rangs à l'entrée du tie-break.
 *
 * Porte `TieBreakController#update` de l'application Rails : les concurrents
 * sont regroupés par rang intermédiaire (figé à l'entrée du tie-break), et
 * seule leur position au sein d'un même groupe d'ex æquo peut être ajustée.
 */
final readonly class TieBreakService
{
    public function __construct(
        private Ranker $ranker,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<RankedGroup<Participant>> */
    public function getParticipantGroups(Taikai $taikai): array
    {
        return $this->ranker->groupByIntermediateRank($taikai->getParticipants());
    }

    /** @return list<RankedGroup<Team>> */
    public function getTeamGroups(Taikai $taikai): array
    {
        return $this->ranker->groupByIntermediateRank($taikai->getTeams());
    }

    /** @param array<int, int> $ranksById nouveau rang, indexé par identifiant */
    public function applyParticipantRanks(Taikai $taikai, array $ranksById, User $user): void
    {
        $this->applyRanks($taikai->getParticipants(), $ranksById, $taikai, $user);
    }

    /** @param array<int, int> $ranksById nouveau rang, indexé par identifiant */
    public function applyTeamRanks(Taikai $taikai, array $ranksById, User $user): void
    {
        $this->applyRanks($taikai->getTeams(), $ranksById, $taikai, $user);
    }

    /**
     * @param list<Participant|Team> $rankables
     * @param array<int, int>        $ranksById
     */
    private function applyRanks(array $rankables, array $ranksById, Taikai $taikai, User $user): void
    {
        foreach ($rankables as $rankable) {
            $id = $rankable->getId();
            if (null === $id || !\array_key_exists($id, $ranksById)) {
                continue;
            }

            $newRank = $ranksById[$id];
            // Comme côté Rails : rien à enregistrer, ni à journaliser, si le rang
            // soumis est celui déjà en place.
            if ($rankable->getRank() === $newRank) {
                continue;
            }

            $rankable->setRank($newRank);
            $taikai->addEvent(TaikaiEvent::tieBreak($taikai, $user, $rankable));
        }

        $this->entityManager->flush();
    }
}

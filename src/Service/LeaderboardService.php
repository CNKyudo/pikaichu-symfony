<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RankedGroup;
use App\Entity\Participant;
use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Entity\Team;
use App\Enum\TaikaiForm;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calcule les classements d'un taikai.
 *
 * Reprend la classe `Leaderboard` de l'application Rails : un classement général
 * et, pour un tournoi à distance, un classement par club hôte.
 */
final readonly class LeaderboardService
{
    public function __construct(
        private Ranker $ranker,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Classement individuel : général, puis par club hôte si le taikai est à distance.
     *
     * @return array{list<RankedGroup<Participant>>, array<int, list<RankedGroup<Participant>>>}
     */
    public function computeIndividualLeaderboard(Taikai $taikai, bool $validated = true): array
    {
        $overall = $this->ranker->rank($taikai->getParticipants(), $taikai, $validated);

        $byDojo = [];
        if ($taikai->isDistributed()) {
            foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
                $byDojo[(int) $participatingDojo->getId()] = $this->ranker->rank(
                    array_values($participatingDojo->getParticipants()->toArray()),
                    $taikai,
                    $validated,
                );
            }
        }

        return [$overall, $byDojo];
    }

    /**
     * Classement par équipes.
     *
     * @return array{list<RankedGroup<Team>>, array<int, list<RankedGroup<Team>>>}
     */
    public function computeTeamLeaderboard(Taikai $taikai, bool $validated = true): array
    {
        if (!$taikai->isForm(TaikaiForm::Team, TaikaiForm::TwoInOne)) {
            throw new \LogicException("computeTeamLeaderboard works only for 'team' and '2in1' taikais");
        }

        $overall = $this->ranker->rank($taikai->getTeams(), $taikai, $validated);

        $byDojo = [];
        if ($taikai->isDistributed()) {
            foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
                $byDojo[(int) $participatingDojo->getId()] = $this->ranker->rank(
                    array_values($participatingDojo->getTeams()->toArray()),
                    $taikai,
                    $validated,
                );
            }
        }

        return [$overall, $byDojo];
    }

    /**
     * Classement d'un tournoi à matchs : l'ordre découle des deux finales
     * (grande finale puis petite finale), vainqueur avant perdant.
     *
     * @return array{list<array{team: Team, match: TaikaiMatch}>, array<int, list<TaikaiMatch>>}
     */
    public function computeMatchesLeaderboard(Taikai $taikai): array
    {
        if (!$taikai->isForm(TaikaiForm::Matches)) {
            throw new \LogicException("computeMatchesLeaderboard works only for 'matches' taikais");
        }

        $podium = [];
        foreach ($taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_FINAL) as $match) {
            foreach ($match->getOrderedTeams() as $team) {
                if (null !== $team) {
                    $podium[] = ['team' => $team, 'match' => $match];
                }
            }
        }

        $byLevel = [];
        foreach ($taikai->getMatches() as $match) {
            $byLevel[$match->getLevel()][] = $match;
        }

        foreach ($byLevel as &$matches) {
            usort($matches, static fn (TaikaiMatch $a, TaikaiMatch $b): int => $a->getIndex() <=> $b->getIndex());
        }

        unset($matches);
        ksort($byLevel);

        return [$podium, $byLevel];
    }

    /**
     * Fige les rangs à l'entrée en tie-break, afin qu'ils puissent ensuite être
     * ajustés à la main. Appelé lors de la transition « Marquage » → « Tie-Break ».
     */
    public function computeIntermediateRanks(Taikai $taikai): void
    {
        match ($taikai->getForm()) {
            TaikaiForm::Individual => $this->applyIndividualRanks($taikai),
            TaikaiForm::Team => $this->applyTeamRanks($taikai),
            TaikaiForm::TwoInOne => (function () use ($taikai): void {
                $this->applyIndividualRanks($taikai);
                $this->applyTeamRanks($taikai);
            })(),
            TaikaiForm::Matches => $this->applyMatchesRanks($taikai),
            null => throw new \LogicException('Unknown taikai form'),
        };

        $this->entityManager->flush();
    }

    /**
     * Remet les rangs à zéro, pour revenir du tie-break au marquage.
     */
    public function clearRanks(Taikai $taikai): void
    {
        foreach ($taikai->getParticipants() as $participant) {
            $participant->setRank(null)->setIntermediateRank(null);
        }

        foreach ($taikai->getTeams() as $team) {
            $team->setRank(null)->setIntermediateRank(null);
        }

        $this->entityManager->flush();
    }

    private function applyIndividualRanks(Taikai $taikai): void
    {
        // Rangs calculés sur les seules flèches validées.
        [$groups] = $this->computeIndividualLeaderboard($taikai, true);
        foreach ($groups as $group) {
            foreach ($group->members as $participant) {
                $participant->setIntermediateRank($group->rank)->setRank($group->rank);
            }
        }
    }

    private function applyTeamRanks(Taikai $taikai): void
    {
        [$groups] = $this->computeTeamLeaderboard($taikai, true);
        foreach ($groups as $group) {
            foreach ($group->members as $team) {
                $team->setIntermediateRank($group->rank)->setRank($group->rank);
            }
        }
    }

    private function applyMatchesRanks(Taikai $taikai): void
    {
        [$podium] = $this->computeMatchesLeaderboard($taikai);
        $rank = 1;
        foreach ($podium as $entry) {
            $entry['team']->setIntermediateRank($rank)->setRank($rank);
            ++$rank;
        }
    }
}

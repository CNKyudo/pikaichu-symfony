<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RankedGroup;
use App\DTO\ScoreValue;
use App\Entity\Participant;
use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Entity\Team;
use App\Enum\TaikaiState;

/**
 * Construit les classements en regroupant les ex æquo.
 *
 * Reprend `RankedAssociationExtension#ranked` de l'application Rails :
 *  - à partir du tie-break, le classement suit le rang saisi manuellement ;
 *  - avant cela, il suit le score, meilleur score en tête.
 *
 * Dans les deux cas les concurrents d'un même rang sont présentés dans l'ordre du
 * tirage au sort.
 */
final class Ranker
{
    /**
     * @template T of Participant|Team
     *
     * @param list<T> $scoreables
     *
     * @return list<RankedGroup<T>>
     */
    public function rank(
        array $scoreables,
        Taikai $taikai,
        bool $validated = true,
        ?TaikaiMatch $match = null,
    ): array {
        if ($taikai->isState(TaikaiState::TieBreak, TaikaiState::Done)) {
            return $this->groupByStoredRank($scoreables);
        }

        return $this->groupByScore($scoreables, $validated, $match);
    }

    /**
     * Classement figé du tie-break : on regroupe sur le rang enregistré.
     *
     * @template T of Participant|Team
     *
     * @param list<T> $scoreables
     *
     * @return list<RankedGroup<T>>
     */
    private function groupByStoredRank(array $scoreables): array
    {
        return $this->groupByField($scoreables, static fn (Participant|Team $s): ?int => $s->getRank());
    }

    /**
     * Groupes d'ex æquo tels qu'ils étaient à l'entrée du tie-break, sur la base
     * du rang intermédiaire figé par `LeaderboardService::computeIntermediateRanks()`
     * — à la différence du rang courant, il n'est jamais modifié par la saisie
     * manuelle qui suit, et sert donc de repère stable pour l'écran d'ajustement.
     *
     * @template T of Participant|Team
     *
     * @param list<T> $scoreables
     *
     * @return list<RankedGroup<T>>
     */
    public function groupByIntermediateRank(array $scoreables): array
    {
        return $this->groupByField($scoreables, static fn (Participant|Team $s): ?int => $s->getIntermediateRank());
    }

    /**
     * @template T of Participant|Team
     *
     * @param list<T>                 $scoreables
     * @param callable(T): (int|null) $fieldOf
     *
     * @return list<RankedGroup<T>>
     */
    private function groupByField(array $scoreables, callable $fieldOf): array
    {
        /** @var array<int, list<T>> $buckets */
        $buckets = [];
        foreach ($scoreables as $scoreable) {
            $buckets[$fieldOf($scoreable) ?? \PHP_INT_MAX][] = $scoreable;
        }

        ksort($buckets);

        $groups = [];
        foreach ($buckets as $rank => $members) {
            $groups[] = new RankedGroup(
                rank: \PHP_INT_MAX === $rank ? \count($groups) + 1 : $rank,
                members: $this->sortByDrawOrder($members),
            );
        }

        return $groups;
    }

    /**
     * Classement provisoire : on regroupe sur la valeur de score, décroissante.
     *
     * @template T of Participant|Team
     *
     * @param list<T> $scoreables
     *
     * @return list<RankedGroup<T>>
     */
    private function groupByScore(array $scoreables, bool $validated, ?TaikaiMatch $match): array
    {
        /** @var array<string, array{score: ScoreValue, members: list<T>}> $buckets */
        $buckets = [];

        foreach ($scoreables as $scoreable) {
            $score = $scoreable->getScore($match)?->toScoreValue($validated) ?? new ScoreValue(hits: 0, value: 0);
            $key = $score->groupKey();

            $buckets[$key] ??= ['score' => $score, 'members' => []];
            $buckets[$key]['members'][] = $scoreable;
        }

        // Meilleur score en tête.
        uasort(
            $buckets,
            static fn (array $a, array $b): int => $b['score']->compareTo($a['score'])
        );

        $groups = [];
        $rank = 1;
        foreach ($buckets as $bucket) {
            $members = $this->sortByDrawOrder($bucket['members']);
            $groups[] = new RankedGroup(
                rank: $rank,
                members: $members,
                score: $bucket['score'],
            );
            // Les ex æquo consomment autant de places qu'il y a de concurrents :
            // après deux premiers ex æquo, le suivant est troisième.
            $rank += \count($members);
        }

        return $groups;
    }

    /**
     * @template T of Participant|Team
     *
     * @param list<T> $scoreables
     *
     * @return list<T>
     */
    private function sortByDrawOrder(array $scoreables): array
    {
        usort(
            $scoreables,
            static fn (Participant|Team $a, Participant|Team $b): int => ($a->getIndex() ?? \PHP_INT_MAX) <=> ($b->getIndex() ?? \PHP_INT_MAX)
        );

        return $scoreables;
    }
}

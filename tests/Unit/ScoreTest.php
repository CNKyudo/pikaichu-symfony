<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Participant;
use App\Entity\Result;
use App\Entity\Score;
use App\Entity\Team;
use App\Enum\ResultStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `hits`/`value` ne comptent que les flèches validées (`final`), alors que
 * `intermediate_hits`/`intermediate_value` comptent aussi celles simplement
 * marquées : voir le piège « hits (compteur définitif) et intermediateHits
 * (compteur provisoire) répondent à des questions différentes » dans
 * MIGRATION.md. Jusqu'ici cette distinction n'était exercée qu'indirectement,
 * via `RankerTest` et les tests fonctionnels de marquage.
 */
#[CoversClass(Score::class)]
final class ScoreTest extends TestCase
{
    public function testRecalculateCountsOnlyFinalArrowsInHitsAndValue(): void
    {
        $score = new Score();
        $this->addResult($score, ResultStatus::Hit, final: true, value: 7);
        $this->addResult($score, ResultStatus::Hit, final: false, value: 9);
        $this->addResult($score, ResultStatus::Miss, final: true);

        $score->recalculateFromResults();

        self::assertSame(1, $score->getHits());
        self::assertSame(7, $score->getValue());
    }

    public function testRecalculateCountsMarkedButUnvalidatedArrowsInIntermediateOnly(): void
    {
        $score = new Score();
        $this->addResult($score, ResultStatus::Hit, final: false, value: 5);

        $score->recalculateFromResults();

        self::assertSame(0, $score->getHits());
        self::assertSame(0, $score->getValue());
        self::assertSame(1, $score->getIntermediateHits());
        self::assertSame(5, $score->getIntermediateValue());
    }

    public function testRecalculateIgnoresMissesAndEmptyArrows(): void
    {
        $score = new Score();
        $this->addResult($score, ResultStatus::Miss, final: true);
        $score->addResult(new Result());

        $score->recalculateFromResults();

        self::assertSame(0, $score->getHits());
        self::assertSame(0, $score->getIntermediateHits());
    }

    public function testRecalculateFromTeamSumsMemberScores(): void
    {
        $team = new Team();

        // Deux flèches validées (7 chacune) et une simplement marquée (7) :
        // hits=2/value=14, intermediateHits=3/intermediateValue=21.
        $this->teamMemberWithScore($team, [[true, 7], [true, 7], [false, 7]]);
        // Une seule flèche, validée.
        $this->teamMemberWithScore($team, [[true, 7]]);

        $teamScore = new Score();
        $teamScore->setTeam($team);
        $teamScore->recalculateFromTeam();

        self::assertSame(3, $teamScore->getHits());
        self::assertSame(21, $teamScore->getValue());
        self::assertSame(4, $teamScore->getIntermediateHits());
        self::assertSame(28, $teamScore->getIntermediateValue());
    }

    public function testRecalculateFromTeamIsNoopWithoutATeam(): void
    {
        $score = new Score();
        $this->addResult($score, ResultStatus::Hit, final: true, value: 7);
        $score->recalculateFromResults();

        $score->recalculateFromTeam();

        // Sans équipe, `recalculateFromTeam()` ne doit pas écraser les compteurs
        // déjà posés par `recalculateFromResults()`.
        self::assertSame(1, $score->getHits());
        self::assertSame(7, $score->getValue());
    }

    public function testIsFinalizedRequiresAllArrowsFinal(): void
    {
        $score = new Score();
        $this->addResult($score, ResultStatus::Hit, final: true);
        $this->addResult($score, ResultStatus::Hit, final: false);

        self::assertFalse($score->isFinalized());
    }

    public function testIsFinalizedIsFalseWithoutAnyArrow(): void
    {
        self::assertFalse(new Score()->isFinalized());
    }

    public function testIsFinalizedIsTrueWhenEveryArrowIsFinal(): void
    {
        $score = new Score();
        $this->addResult($score, ResultStatus::Hit, final: true);
        $this->addResult($score, ResultStatus::Miss, final: true);

        self::assertTrue($score->isFinalized());
    }

    private function addResult(Score $score, ResultStatus $status, bool $final, ?int $value = null): Result
    {
        $result = new Result();
        $result->setStatus($status)->setFinal($final)->setValue($value);
        $score->addResult($result);

        return $result;
    }

    /** @param list<array{bool, int}> $hits paires (validée, valeur) */
    private function teamMemberWithScore(Team $team, array $hits): Participant
    {
        $participant = new Participant();
        $team->addParticipant($participant);

        $score = new Score();
        $score->setParticipant($participant);
        foreach ($hits as [$final, $value]) {
            $this->addResult($score, ResultStatus::Hit, $final, $value);
        }

        $score->recalculateFromResults();
        $participant->addScore($score);

        return $participant;
    }
}

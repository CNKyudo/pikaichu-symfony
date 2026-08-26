<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Result;
use App\Entity\Score;
use App\Entity\Taikai;
use App\Entity\TaikaiTransition;
use App\Enum\ResultStatus;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiState;
use App\Service\Ranker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie le regroupement des ex æquo et la numérotation des rangs, qui
 * reprennent `RankedAssociationExtension#ranked` de l'application Rails.
 */
#[CoversClass(Ranker::class)]
final class RankerTest extends TestCase
{
    private Ranker $ranker;

    private Taikai $taikai;

    private ParticipatingDojo $participatingDojo;

    protected function setUp(): void
    {
        $this->ranker = new Ranker();

        $this->taikai = new Taikai();
        $this->taikai->setForm(TaikaiForm::Individual)->setTotalNumArrows(4);

        $this->participatingDojo = new ParticipatingDojo();
        $this->taikai->addParticipatingDojo($this->participatingDojo);
    }

    public function testOrdersByScoreDescending(): void
    {
        $weak = $this->makeParticipant('Weak', index: 1, hits: 1);
        $strong = $this->makeParticipant('Strong', index: 2, hits: 4);
        $middle = $this->makeParticipant('Middle', index: 3, hits: 2);

        $groups = $this->ranker->rank([$weak, $strong, $middle], $this->taikai);

        self::assertCount(3, $groups);
        self::assertSame('Strong', $groups[0]->members[0]->getFirstname());
        self::assertSame('Middle', $groups[1]->members[0]->getFirstname());
        self::assertSame('Weak', $groups[2]->members[0]->getFirstname());
    }

    /**
     * Deux ex æquo occupent le même rang, et le suivant est troisième :
     * les places consommées comptent.
     */
    public function testTiedCompetitorsShareRankAndConsumePlaces(): void
    {
        $first = $this->makeParticipant('First', index: 1, hits: 4);
        $tied = $this->makeParticipant('Tied', index: 2, hits: 4);
        $third = $this->makeParticipant('Third', index: 3, hits: 1);

        $groups = $this->ranker->rank([$first, $tied, $third], $this->taikai);

        self::assertCount(2, $groups);

        self::assertSame(1, $groups[0]->rank);
        self::assertCount(2, $groups[0]->members);
        self::assertTrue($groups[0]->isTied());

        self::assertSame(3, $groups[1]->rank);
        self::assertSame('Third', $groups[1]->members[0]->getFirstname());
    }

    /** Au sein d'un même rang, l'ordre de tirage au sort départage l'affichage. */
    public function testTiedCompetitorsAreOrderedByDrawOrder(): void
    {
        $late = $this->makeParticipant('Late', index: 9, hits: 3);
        $early = $this->makeParticipant('Early', index: 2, hits: 3);

        $groups = $this->ranker->rank([$late, $early], $this->taikai);

        self::assertCount(1, $groups);
        self::assertSame('Early', $groups[0]->members[0]->getFirstname());
        self::assertSame('Late', $groups[0]->members[1]->getFirstname());
    }

    /**
     * À partir du tie-break, c'est le rang saisi manuellement qui prime,
     * même s'il contredit les scores.
     */
    public function testUsesStoredRankOnceInTieBreak(): void
    {
        $this->moveTaikaiTo(TaikaiState::TieBreak);

        $bestScore = $this->makeParticipant('BestScore', index: 1, hits: 4);
        $bestScore->setRank(2);

        $promoted = $this->makeParticipant('Promoted', index: 2, hits: 4);
        $promoted->setRank(1);

        $groups = $this->ranker->rank([$bestScore, $promoted], $this->taikai);

        self::assertCount(2, $groups);
        self::assertSame(1, $groups[0]->rank);
        self::assertSame('Promoted', $groups[0]->members[0]->getFirstname());
        self::assertSame(2, $groups[1]->rank);
        self::assertSame('BestScore', $groups[1]->members[0]->getFirstname());
    }

    /**
     * Le classement provisoire compte les flèches marquées mais pas encore
     * validées, contrairement au classement validé.
     */
    public function testProvisionalRankingCountsUnvalidatedArrows(): void
    {
        $participant = $this->makeParticipant('Shooter', index: 1, hits: 0);
        $score = $participant->getScore();
        self::assertInstanceOf(Score::class, $score);

        // Trois touchés marqués, dont un seul validé.
        $this->addResults($score, [
            [ResultStatus::Hit, true],
            [ResultStatus::Hit, false],
            [ResultStatus::Hit, false],
        ]);
        $score->recalculateFromResults();

        $validated = $this->ranker->rank([$participant], $this->taikai, validated: true);
        $provisional = $this->ranker->rank([$participant], $this->taikai, validated: false);

        self::assertSame(1, $validated[0]->score?->hits);
        self::assertSame(3, $provisional[0]->score?->hits);
    }

    public function testEmptyInputProducesNoGroups(): void
    {
        self::assertSame([], $this->ranker->rank([], $this->taikai));
    }

    /**
     * Crée un participant doté d'un score comportant `$hits` touchés validés.
     */
    private function makeParticipant(string $firstname, int $index, int $hits): Participant
    {
        $participant = new Participant();
        $participant->setFirstname($firstname)
            ->setLastname('Test')
            ->setIndex($index);
        $this->participatingDojo->addParticipant($participant);

        $score = new Score();
        $score->setParticipant($participant);

        $participant->addScore($score);

        $marks = [];
        for ($i = 0; $i < $hits; ++$i) {
            $marks[] = [ResultStatus::Hit, true];
        }

        $this->addResults($score, $marks);
        $score->recalculateFromResults();

        return $participant;
    }

    /**
     * @param list<array{ResultStatus, bool}> $marks statut et caractère validé
     */
    private function addResults(Score $score, array $marks): void
    {
        $index = $score->getResults()->count();
        foreach ($marks as [$status, $final]) {
            $result = new Result();
            $result->setScore($score)
                ->setRound(1)
                ->setIndex(++$index)
                ->setStatus($status)
                ->setFinal($final);
            $score->addResult($result);
        }
    }

    private function moveTaikaiTo(TaikaiState $state): void
    {
        $transition = new TaikaiTransition();
        $transition->setTaikai($this->taikai)
            ->setToState($state)
            ->setSortKey(0)
            ->setMostRecent(true);
        $this->taikai->addTransition($transition);
    }
}

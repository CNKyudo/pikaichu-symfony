<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Entity\Team;
use App\Enum\ResultStatus;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Service\MatchService;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Construction du tableau final et désignation des vainqueurs, reprise de
 * `Taikai.create_matches` et `Match#select_winner`.
 */
final class MatchServiceTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;

    private MatchService $matchService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->matchService = $container->get(MatchService::class);

        $this->resetDatabase($this->entityManager);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    /** L'appariement 0-7/4-3/2-5/6-1 reprend le guide des tournois de novembre 2021. */
    public function testCreatesQuarterFinalsForEightTeams(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 8);

        $this->matchService->createBracket($taikai, $teams);

        $quarterFinals = $taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_QUARTER_FINAL);
        self::assertCount(4, $quarterFinals);

        $pairs = array_map(
            static fn (TaikaiMatch $m): array => [$m->getTeam1()?->getShortname(), $m->getTeam2()?->getShortname()],
            $quarterFinals,
        );
        self::assertSame([
            ['T1', 'T8'],
            ['T5', 'T4'],
            ['T3', 'T6'],
            ['T7', 'T2'],
        ], $pairs);

        self::assertCount(2, $taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_SEMI_FINAL));
        self::assertCount(2, $taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_FINAL));
    }

    public function testCreatesSemiFinalsForFourTeams(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 4);

        $this->matchService->createBracket($taikai, $teams);

        $semiFinals = $taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_SEMI_FINAL);
        self::assertCount(2, $semiFinals);

        $pairs = array_map(
            static fn (TaikaiMatch $m): array => [$m->getTeam1()?->getShortname(), $m->getTeam2()?->getShortname()],
            $semiFinals,
        );
        self::assertSame([['T1', 'T4'], ['T3', 'T2']], $pairs);

        self::assertCount(2, $taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_FINAL));
        self::assertEmpty($taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_QUARTER_FINAL));
    }

    public function testRejectsUnsupportedBracketSize(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 5);

        $this->expectException(\InvalidArgumentException::class);
        $this->matchService->createBracket($taikai, $teams);
    }

    /** Le vainqueur d'une demi-finale monte en grande finale, le perdant en petite finale. */
    public function testSelectWinnerPromotesToNextMatchAndDemotesLoserToSmallFinal(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 4);
        $this->matchService->createBracket($taikai, $teams);

        $semiFinal1 = $this->findMatch($taikai, TaikaiMatch::LEVEL_SEMI_FINAL, 1);
        $error = $this->matchService->selectWinner($semiFinal1, 1);
        self::assertNull($error);

        $grandFinal = $this->findMatch($taikai, TaikaiMatch::LEVEL_FINAL, TaikaiMatch::INDEX_GRAND_FINAL);
        $smallFinal = $this->findMatch($taikai, TaikaiMatch::LEVEL_FINAL, TaikaiMatch::INDEX_SMALL_FINAL);

        self::assertSame('T1', $grandFinal->getTeam1()?->getShortname());
        self::assertSame('T4', $smallFinal->getTeam1()?->getShortname());
    }

    /**
     * Rails refuse de rejouer un match dont le match de destination a déjà des
     * marques saisies : cela protège contre une composition d'équipe qui aurait
     * déjà été partiellement tirée.
     */
    public function testCannotSelectWinnerIfTargetMatchHasDefinedResults(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 4);
        $this->matchService->createBracket($taikai, $teams);

        $semiFinal1 = $this->findMatch($taikai, TaikaiMatch::LEVEL_SEMI_FINAL, 1);
        $this->matchService->selectWinner($semiFinal1, 1);

        $grandFinal = $this->findMatch($taikai, TaikaiMatch::LEVEL_FINAL, TaikaiMatch::INDEX_GRAND_FINAL);
        $participant = $grandFinal->getTeam(1)?->getParticipants()->first() ?: null;
        self::assertInstanceOf(Participant::class, $participant);
        $result = $participant->getScore($grandFinal)?->getResultsForRound(1)[0] ?? null;
        self::assertNotNull($result);
        $result->setStatus(ResultStatus::Hit);

        // Recharge tout depuis la base : `selectWinner()` s'appuie sur des
        // collections en lecture différée qui, en mémoire depuis leur création,
        // ne verraient pas le résultat qu'on vient d'ajouter — exactement comme
        // deux requêtes HTTP séparées le feraient naturellement.
        $this->commitSeeding($this->entityManager);
        $taikaiId = $taikai->getId();
        $taikai = $this->entityManager->getRepository(Taikai::class)->find($taikaiId);
        self::assertInstanceOf(Taikai::class, $taikai);

        self::assertTrue(
            $this->findMatch($taikai, TaikaiMatch::LEVEL_FINAL, TaikaiMatch::INDEX_GRAND_FINAL)->hasDefinedResults(),
            'sanity check: grand final should have a defined result',
        );

        $semiFinal2 = $this->findMatch($taikai, TaikaiMatch::LEVEL_SEMI_FINAL, 2);
        $error = $this->matchService->selectWinner($semiFinal2, 1);

        self::assertSame('match.defined_results_for_target_match', $error);
    }

    private function findMatch(Taikai $taikai, int $level, int $index): TaikaiMatch
    {
        foreach ($taikai->getMatchesAtLevel($level) as $match) {
            if ($match->getIndex() === $index) {
                return $match;
            }
        }

        self::fail(\sprintf('No match found at level %d index %d', $level, $index));
    }

    private function createTaikai(): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Matches)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(4)
            ->setNumTargets(6)
            ->setTachiSize(1)
            ->setDistributed(false);
        $this->entityManager->persist($taikai);
        $this->entityManager->flush();

        return $taikai;
    }

    /** @return list<Team> */
    private function createTeams(Taikai $taikai, int $count): array
    {
        $dojo = new Dojo();
        $dojo->setShortname('nantes')->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $teams = [];
        for ($i = 1; $i <= $count; ++$i) {
            $team = new Team();
            $team->setShortname('T'.$i);
            $participatingDojo->addTeam($team);
            $this->entityManager->persist($team);

            $participant = new Participant();
            $participant->setFirstname('Archer'.$i)->setLastname('T'.$i)->setClub('nantes');
            $participatingDojo->addParticipant($participant);
            $team->addParticipant($participant);
            $this->entityManager->persist($participant);

            $teams[] = $team;
        }

        $this->entityManager->flush();

        return $teams;
    }
}

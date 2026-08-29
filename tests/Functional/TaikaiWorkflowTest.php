<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\TaikaiEvent;
use App\Entity\User;
use App\Enum\ResultStatus;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Exception\TransitionNotAllowedException;
use App\Service\DrawService;
use App\Service\MarkingService;
use App\Service\TaikaiStateMachine;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Parcourt un taikai individuel de bout en bout, en vérifiant que chaque garde
 * de la machine à états se comporte comme celle de l'application Rails.
 */
final class TaikaiWorkflowTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;

    private TaikaiStateMachine $stateMachine;

    private DrawService $drawService;

    private MarkingService $markingService;

    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->stateMachine = $container->get(TaikaiStateMachine::class);
        $this->drawService = $container->get(DrawService::class);
        $this->markingService = $container->get(MarkingService::class);

        $this->loadStaffRoles();

        $this->user = $this->createUser($container->get(UserPasswordHasherInterface::class));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testNewTaikaiStartsInPreparationState(): void
    {
        $taikai = $this->createTaikai();

        self::assertSame(TaikaiState::New, $taikai->getCurrentState());
    }

    public function testCanMoveFromPreparationToRegistration(): void
    {
        $taikai = $this->createTaikai();

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->user);

        self::assertSame(TaikaiState::Registration, $taikai->getCurrentState());
    }

    /**
     * Sans directeur de tournoi, juge de shajo et juge de cible, l'accès au
     * marquage est refusé.
     */
    public function testCannotEnterMarkingWithoutRequiredStaff(): void
    {
        $taikai = $this->createTaikai();
        $this->addParticipatingDojo($taikai, participantCount: 3);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->user);
        $this->drawService->draw($this->firstDojo($taikai));

        self::assertFalse($this->stateMachine->canTransitionTo($taikai, TaikaiState::Marking));
        self::assertSame(
            'taikai.transition.missing_required_staff',
            $this->stateMachine->getBlockingReason($taikai, TaikaiState::Marking),
        );

        $this->expectException(TransitionNotAllowedException::class);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $this->user);
    }

    /** Le tirage au sort est également un prérequis au marquage. */
    public function testCannotEnterMarkingBeforeDraw(): void
    {
        $taikai = $this->createTaikai();
        $this->addRequiredStaff($taikai);
        $this->addParticipatingDojo($taikai, participantCount: 3);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->user);

        self::assertSame(
            'taikai.transition.draw_not_done',
            $this->stateMachine->getBlockingReason($taikai, TaikaiState::Marking),
        );
    }

    /**
     * Une fois le staff complet et le tirage effectué, l'entrée en marquage
     * matérialise les tachis, les scores et toutes les flèches à saisir.
     */
    public function testEnteringMarkingCreatesScoreSheets(): void
    {
        $taikai = $this->readyForMarking(participantCount: 3);

        self::assertSame(TaikaiState::Marking, $taikai->getCurrentState());

        $participatingDojo = $this->firstDojo($taikai);
        self::assertCount(3, $taikai->getParticipants());

        foreach ($taikai->getParticipants() as $participant) {
            $score = $participant->getScore();
            self::assertNotNull($score, 'chaque participant doit avoir une feuille de marque');
            // 8 flèches au total = 2 séries de 4.
            self::assertCount(8, $score->getResults());
        }

        // Un tachi par groupe de tir et par série : 1 groupe × 2 séries.
        self::assertCount(2, $participatingDojo->getTachis());
    }

    /** Revenir à l'enregistrement démonte les feuilles de marque. */
    public function testGoingBackToRegistrationRemovesScoreSheets(): void
    {
        $taikai = $this->readyForMarking(participantCount: 3);

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->user);

        self::assertSame(TaikaiState::Registration, $taikai->getCurrentState());
        foreach ($taikai->getParticipants() as $participant) {
            self::assertCount(0, $participant->getScores());
        }
    }

    /** Tant que toutes les flèches ne sont pas validées, le tie-break est fermé. */
    public function testCannotEnterTieBreakWhileResultsAreNotFinalized(): void
    {
        $taikai = $this->readyForMarking(participantCount: 3);

        self::assertSame(
            'taikai.transition.results_not_finalized',
            $this->stateMachine->getBlockingReason($taikai, TaikaiState::TieBreak),
        );
    }

    /**
     * Parcours complet : on marque et valide toutes les flèches, puis on entre
     * en tie-break, ce qui fige le classement.
     */
    public function testFullMarkingThenTieBreakFreezesRanks(): void
    {
        $taikai = $this->readyForMarking(participantCount: 3);

        // Le premier archer touche tout, les autres manquent tout.
        $participants = $taikai->getParticipants();
        foreach ($participants as $position => $participant) {
            $status = 0 === $position ? ResultStatus::Hit : ResultStatus::Miss;

            for ($round = 1; $round <= $taikai->getNumRounds(); ++$round) {
                for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
                    $this->markingService->addResult($participant, $status);
                }

                $this->markingService->finalizeRound($participant, $round);
            }
        }

        self::assertTrue($taikai->isFinalized());
        self::assertTrue($this->stateMachine->canTransitionTo($taikai, TaikaiState::TieBreak));

        $this->stateMachine->transitionTo($taikai, TaikaiState::TieBreak, $this->user);

        self::assertSame(TaikaiState::TieBreak, $taikai->getCurrentState());
        self::assertSame(8, $participants[0]->getScore()?->getHits());
        self::assertSame(1, $participants[0]->getRank(), 'le meilleur score doit être premier');
        // Les deux archers à zéro sont ex æquo au rang 2.
        self::assertSame(2, $participants[1]->getRank());
        self::assertSame(2, $participants[2]->getRank());
    }

    /** Une série ne peut être entamée que si la précédente est validée. */
    public function testCannotMarkNextRoundBeforeValidatingPrevious(): void
    {
        $taikai = $this->readyForMarking(participantCount: 1);
        $participant = $taikai->getParticipants()[0];

        // Première série marquée mais volontairement pas validée.
        for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
            $this->markingService->addResult($participant, ResultStatus::Hit);
        }

        $this->expectException(\App\Exception\MarkingException::class);
        $this->markingService->addResult($participant, ResultStatus::Hit);
    }

    /** Le journal d'évènements conserve la trace de chaque changement d'étape. */
    public function testTransitionsAreRecordedInHistory(): void
    {
        $taikai = $this->createTaikai();
        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->user);

        self::assertCount(1, $taikai->getEvents());
        $event = $taikai->getEvents()->first();
        self::assertInstanceOf(TaikaiEvent::class, $event);
        self::assertSame(['from' => 'new', 'to' => 'registration'], $event->getData());
    }

    // ─── Fixtures locales ────────────────────────────────────────────────────

    /** Premier club hôte du taikai, en garantissant le type pour l'analyse statique. */
    private function firstDojo(Taikai $taikai): ParticipatingDojo
    {
        $participatingDojo = $taikai->getParticipatingDojos()->first();
        self::assertInstanceOf(ParticipatingDojo::class, $participatingDojo);

        return $participatingDojo;
    }

    /** Amène un taikai jusqu'à l'étape « Marquage », prêt à saisir. */
    private function readyForMarking(int $participantCount): Taikai
    {
        $taikai = $this->createTaikai();
        $this->addRequiredStaff($taikai);
        $this->addParticipatingDojo($taikai, $participantCount);

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->user);
        $this->drawService->draw($this->firstDojo($taikai));
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $this->user);

        return $taikai;
    }

    private function createTaikai(): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('test-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring(TaikaiScoring::Kinteki)
            // 8 flèches = 2 séries de 4, pour raccourcir les tests.
            ->setTotalNumArrows(8)
            ->setNumTargets(6)
            ->setTachiSize(3)
            ->setDistributed(false);

        $this->entityManager->persist($taikai);
        $this->entityManager->flush();

        return $taikai;
    }

    private function addRequiredStaff(Taikai $taikai): void
    {
        foreach (StaffRoleCode::requiredForMarking() as $code) {
            $staff = new Staff();
            $staff->setRole($this->findRole($code))->setUser($this->user);
            $taikai->addStaff($staff);
            $this->entityManager->persist($staff);
        }

        $this->entityManager->flush();
    }

    private function addParticipatingDojo(Taikai $taikai, int $participantCount): ParticipatingDojo
    {
        $dojo = new Dojo();
        $dojo->setShortname('DOJO-'.bin2hex(random_bytes(3)))
            ->setName('Dojo de test')
            ->setCity('Nantes')
            ->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName($dojo->getShortname());
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        for ($i = 1; $i <= $participantCount; ++$i) {
            $participant = new Participant();
            $participant->setFirstname('Archer'.$i)
                ->setLastname('Test')
                ->setClub((string) $dojo->getShortname());
            $participatingDojo->addParticipant($participant);
            $this->entityManager->persist($participant);
        }

        $this->entityManager->flush();

        return $participatingDojo;
    }

    private function createUser(UserPasswordHasherInterface $hasher): User
    {
        $user = new User();
        $user->setEmailAddress('workflow@pikaichu.test')
            ->setFirstname('Workflow')
            ->setLastname('Tester')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function findRole(StaffRoleCode $code): StaffRole
    {
        $role = $this->entityManager->getRepository(StaffRole::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(StaffRole::class, $role);

        return $role;
    }

    private function loadStaffRoles(): void
    {
        foreach (StaffRoleCode::cases() as $code) {
            $role = new StaffRole();
            $role->setCode($code)
                ->setLabel(['fr' => $code->value, 'en' => $code->value])
                ->setDescription(['fr' => $code->value, 'en' => $code->value]);
            $this->entityManager->persist($role);
        }

        $this->entityManager->flush();
    }
}

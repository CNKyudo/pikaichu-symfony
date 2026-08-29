<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Result;
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
use App\Service\DrawService;
use App\Service\MarkingService;
use App\Service\TaikaiStateMachine;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rectification d'une flèche déjà validée, portée de `rectification_controller`.
 */
final class RectificationControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private TaikaiStateMachine $stateMachine;

    private DrawService $drawService;

    private MarkingService $markingService;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->stateMachine = $container->get(TaikaiStateMachine::class);
        $this->drawService = $container->get(DrawService::class);
        $this->markingService = $container->get(MarkingService::class);

        $this->loadStaffRoles();

        $this->admin = $this->createUser('admin@pikaichu.test');
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testIndexListsMarkedResults(): void
    {
        $taikai = $this->markedTaikai(TaikaiScoring::Kinteki, StaffRoleCode::TaikaiAdmin);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->indexPath($taikai));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href*="/rectification/"]')->count());
    }

    /** Rails exclut « incertain » des rectifications possibles en kinteki : seul hit/miss. */
    public function testCanRectifyAValidatedKintekiArrow(): void
    {
        $taikai = $this->markedTaikai(TaikaiScoring::Kinteki, StaffRoleCode::TaikaiAdmin);
        $result = $this->firstResult($taikai);
        self::assertTrue($result->isFinal());
        self::assertSame(ResultStatus::Hit, $result->getStatus());
        $scoreHitsBefore = $result->getScore()?->getHits();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai, $result));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="result_rectification"]')->form([
            'result_rectification[status]' => 'miss',
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/marking');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Result::class)->find($result->getId());
        self::assertSame(ResultStatus::Miss, $reloaded?->getStatus());
        self::assertTrue($reloaded->isOverriden());

        // Le score du participant doit refléter la rectification.
        self::assertLessThan($scoreHitsBefore, $reloaded->getScore()?->getHits());

        $events = $this->entityManager->getRepository(TaikaiEvent::class)
            ->findBy(['taikai' => $taikai->getId(), 'category' => TaikaiEvent::CATEGORY_RECTIFICATION]);
        self::assertCount(1, $events);
    }

    public function testCanRectifyAValidatedEntekiArrow(): void
    {
        $taikai = $this->markedTaikai(TaikaiScoring::Enteki, StaffRoleCode::TaikaiAdmin);
        $result = $this->firstResult($taikai);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai, $result));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="result_rectification"]')->form([
            'result_rectification[value]' => '7',
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/marking');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Result::class)->find($result->getId());
        self::assertSame(7, $reloaded?->getValue());
        self::assertSame(ResultStatus::Hit, $reloaded->getStatus());
        self::assertTrue($reloaded->isOverriden());
    }

    public function testCannotRectifyOutsideMarkingState(): void
    {
        $taikai = $this->markedTaikai(TaikaiScoring::Kinteki, StaffRoleCode::TaikaiAdmin);
        $result = $this->firstResult($taikai);
        $this->stateMachine->transitionTo($taikai, TaikaiState::TieBreak, $this->admin);

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->editPath($taikai, $result));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Corrige un écart avec la référence Rails : `rectification_update?` ne
     * couvre que `ADMIN_ROLES` (taikai_admin), pas le directeur de tournoi.
     */
    public function testChairmanCannotRectify(): void
    {
        $taikai = $this->markedTaikai(TaikaiScoring::Kinteki, StaffRoleCode::Chairman);
        $result = $this->firstResult($taikai);

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->editPath($taikai, $result));

        self::assertResponseStatusCodeSame(403);
    }

    public function testPlainUserCannotAccess(): void
    {
        $taikai = $this->markedTaikai(TaikaiScoring::Kinteki, StaffRoleCode::TaikaiAdmin);
        $result = $this->firstResult($taikai);

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));

        $this->client->request('GET', $this->editPath($taikai, $result));

        self::assertResponseStatusCodeSame(403);
    }

    private function indexPath(Taikai $taikai): string
    {
        return '/taikais/'.$taikai->getId().'/rectification';
    }

    private function editPath(Taikai $taikai, Result $result): string
    {
        return '/taikais/'.$taikai->getId().'/rectification/'.$result->getId().'/edit';
    }

    private function firstResult(Taikai $taikai): Result
    {
        $participant = $taikai->getParticipants()[0];
        $result = $participant->getScore()?->getResultsForRound(1)[0] ?? null;
        self::assertInstanceOf(Result::class, $result);

        return $result;
    }

    /**
     * Un taikai en marquage, toutes les séries entièrement touchées et
     * validées — de sorte que le taikai puisse, au besoin, avancer jusqu'au
     * tie-break (`testCannotRectifyOutsideMarkingState`).
     */
    private function markedTaikai(TaikaiScoring $scoring, StaffRoleCode $extraStaffRole): Taikai
    {
        $taikai = $this->createTaikai($scoring);
        $this->addRequiredStaff($taikai);
        if (StaffRoleCode::TaikaiAdmin === $extraStaffRole) {
            $this->addStaff($taikai, StaffRoleCode::TaikaiAdmin, $this->admin);
        }

        $participatingDojo = $this->addParticipatingDojo($taikai, 1);

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->admin);
        $this->drawService->draw($participatingDojo);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $this->admin);

        $participant = $taikai->getParticipants()[0];
        for ($round = 1; $round <= $taikai->getNumRounds(); ++$round) {
            for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
                $this->markingService->addResult($participant, ResultStatus::Hit, TaikaiScoring::Enteki === $scoring ? 5 : null);
            }

            $this->markingService->finalizeRound($participant, $round);
        }

        return $taikai;
    }

    private function createTaikai(TaikaiScoring $scoring): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring($scoring)
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
            $this->addStaff($taikai, $code, $this->admin);
        }
    }

    private function addStaff(Taikai $taikai, StaffRoleCode $code, User $user): void
    {
        $staff = new Staff();
        $staff->setRole($this->findRole($code))->setUser($user);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);
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
        $participatingDojo->setDojo($dojo)->setDisplayName((string) $dojo->getShortname());
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

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Test')
            ->setLastname('User')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password123'));

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

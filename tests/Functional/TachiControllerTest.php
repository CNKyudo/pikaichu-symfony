<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
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
 * Suivi des tachis sur le shajo, porté de `tachis_controller`.
 */
final class TachiControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UserPasswordHasherInterface $passwordHasher;

    private TaikaiStateMachine $stateMachine;

    private DrawService $drawService;

    private MarkingService $markingService;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->stateMachine = $container->get(TaikaiStateMachine::class);
        $this->drawService = $container->get(DrawService::class);
        $this->markingService = $container->get(MarkingService::class);

        $this->resetDatabase($this->entityManager);
        $this->loadStaffRoles();

        $this->admin = $this->createUser('admin@pikaichu.test');
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testIndexShowsTachiWithParticipantsAndResults(): void
    {
        [$taikai, $participatingDojo] = $this->markingTaikai();
        $participant = $taikai->getParticipants()[0];
        $this->markingService->addResult($participant, ResultStatus::Hit);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request(
            'GET',
            \sprintf('/taikais/%d/participating-dojos/%d/tachis', $taikai->getId(), $participatingDojo->getId()),
        );

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString($participant->getDisplayName(), $text);
        self::assertStringContainsString('◯', $text);
    }

    public function testRequiresAuthentication(): void
    {
        [$taikai, $participatingDojo] = $this->markingTaikai();
        $this->commitSeeding($this->entityManager);

        static::ensureKernelShutdown();

        $anonymousClient = static::createClient();
        $anonymousClient->request(
            'GET',
            \sprintf('/taikais/%d/participating-dojos/%d/tachis', $taikai->getId(), $participatingDojo->getId()),
        );

        self::assertResponseRedirects('/login');
    }

    /** @return array{Taikai, ParticipatingDojo} */
    private function markingTaikai(): array
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(8)
            ->setNumTargets(6)
            ->setTachiSize(3)
            ->setDistributed(false);
        $this->entityManager->persist($taikai);

        foreach (StaffRoleCode::requiredForMarking() as $code) {
            $staff = new Staff();
            $staff->setRole($this->findRole($code))->setUser($this->admin);
            $taikai->addStaff($staff);
            $this->entityManager->persist($staff);
        }

        $dojo = new Dojo();
        $dojo->setShortname('nantes')->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $participant = new Participant();
        $participant->setFirstname('Yumi')->setLastname('Tanaka')->setClub('nantes');
        $participatingDojo->addParticipant($participant);
        $this->entityManager->persist($participant);

        $this->entityManager->flush();

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->admin);
        $this->drawService->draw($participatingDojo);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $this->admin);

        return [$taikai, $participatingDojo];
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Test')
            ->setLastname('User')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));

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

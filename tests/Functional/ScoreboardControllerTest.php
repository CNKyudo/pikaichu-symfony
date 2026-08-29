<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Scoreboard;
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
 * Affichage public par clé d'API, porté de `scoreboard_controller`.
 */
final class ScoreboardControllerTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testShowsCurrentTachiWithoutAuthentication(): void
    {
        [, $participatingDojo] = $this->markingTaikai();
        $participant = $participatingDojo->getParticipants()[0];
        $scoreboard = $this->createScoreboard($participatingDojo);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/scoreboard/'.$scoreboard->getApiKey());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($participant->getDisplayName(), $crawler->filter('body')->text());
    }

    public function testJsonFormatMatchesTaikaiAndTachiShape(): void
    {
        [$taikai, $participatingDojo] = $this->markingTaikai();
        $scoreboard = $this->createScoreboard($participatingDojo);

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/scoreboard/'.$scoreboard->getApiKey().'.json');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($taikai->getShortname(), $data['taikai']['shortname']);
        self::assertSame(1, $data['tachi']['round']);
        self::assertSame(1, $data['tachi']['index']);
        self::assertCount(2, $data['tachi']['participants']);
    }

    /**
     * Avant le tirage au sort, aucun tachi n'existe encore : les informations
     * du taikai doivent malgré tout être présentes (elles ne dépendent pas du
     * tachi affiché).
     */
    public function testJsonIncludesTaikaiInfoEvenWithoutActiveTachi(): void
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

        $dojo = new Dojo();
        $dojo->setShortname('nantes')->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);
        $this->entityManager->flush();

        $scoreboard = $this->createScoreboard($participatingDojo);
        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/scoreboard/'.$scoreboard->getApiKey().'.json');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($taikai->getShortname(), $data['taikai']['shortname']);
        self::assertArrayNotHasKey('tachi', $data);
    }

    /**
     * Le tachi qui vient tout juste d'être validé reste affiché le temps du
     * délai configuré, avant de basculer sur le tachi suivant.
     */
    public function testShowsJustFinishedTachiWithinDelay(): void
    {
        [$taikai, $participatingDojo] = $this->markingTaikai(numTargets: 1);
        $participants = $participatingDojo->getParticipants();
        self::assertCount(2, $participants);

        // Le tirage étant aléatoire, on retrouve l'archer du premier tachi par
        // son ordre de passage plutôt que de supposer l'ordre du tableau.
        $firstDrawnParticipant = current(array_filter(
            iterator_to_array($participants),
            static fn (Participant $p): bool => 1 === $p->getIndex(),
        ));
        self::assertInstanceOf(Participant::class, $firstDrawnParticipant);

        // Valide entièrement le premier tachi (un seul archer, une cible).
        for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
            $this->markingService->addResult($firstDrawnParticipant, ResultStatus::Hit);
        }

        $this->markingService->finalizeRound($firstDrawnParticipant, 1);

        $scoreboard = $this->createScoreboard($participatingDojo);
        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/scoreboard/'.$scoreboard->getApiKey().'.json');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        // Le tachi 1 (juste terminé) doit rester affiché, pas le tachi 2.
        self::assertSame(1, $data['tachi']['index']);
    }

    public function testUnknownApiKeyReturns404(): void
    {
        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/scoreboard/unknown-key');

        self::assertResponseStatusCodeSame(404);
    }

    private function createScoreboard(ParticipatingDojo $participatingDojo): Scoreboard
    {
        $scoreboard = new Scoreboard();
        $scoreboard->setParticipatingDojo($participatingDojo)
            ->setApiKey('key-'.bin2hex(random_bytes(8)))
            ->setDelay(15);

        $this->entityManager->persist($scoreboard);
        $this->entityManager->flush();

        return $scoreboard;
    }

    /** @return array{Taikai, ParticipatingDojo} */
    private function markingTaikai(int $numTargets = 6): array
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(8)
            ->setNumTargets($numTargets)
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

        foreach (['Yumi', 'Haruki'] as $firstname) {
            $participant = new Participant();
            $participant->setFirstname($firstname)->setLastname('Tanaka')->setClub('nantes');
            $participatingDojo->addParticipant($participant);
            $this->entityManager->persist($participant);
        }

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

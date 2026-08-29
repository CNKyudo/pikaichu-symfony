<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ResultStatus;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Exception\TaikaiGenerationException;
use App\Service\DrawService;
use App\Service\MarkingService;
use App\Service\TaikaiGenerationService;
use App\Service\TaikaiStateMachine;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Génération de la 2ᵉ partie (tableau à matchs) d'un 2-en-1, portée de
 * `taikais#generate` / `Taikai.create_from_2in1`.
 */
final class TaikaiGenerationTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UserPasswordHasherInterface $passwordHasher;

    private TaikaiStateMachine $stateMachine;

    private DrawService $drawService;

    private MarkingService $markingService;

    private TaikaiGenerationService $generationService;

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
        $this->generationService = $container->get(TaikaiGenerationService::class);

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

    public function testGeneratesAMatchTaikaiFromAFinalizedTwoInOne(): void
    {
        $taikai = $this->finishedTwoInOneTaikai(4);

        $newTaikai = $this->generationService->generateFromTwoInOne($taikai, $this->admin, 4);

        self::assertSame(TaikaiForm::Matches, $newTaikai->getForm());
        self::assertSame($taikai->getShortname().'-part2', $newTaikai->getShortname());
        self::assertCount(4, $newTaikai->getTeams());
        self::assertCount(2, $newTaikai->getMatchesAtLevel(2));
        self::assertCount(2, $newTaikai->getMatchesAtLevel(1));

        // L'administrateur qui génère n'est copié qu'une fois.
        $adminStaffCount = 0;
        foreach ($newTaikai->getStaffs() as $staff) {
            if ($staff->getUser() === $this->admin && StaffRoleCode::TaikaiAdmin === $staff->getRole()?->getCode()) {
                ++$adminStaffCount;
            }
        }

        self::assertSame(1, $adminStaffCount);
    }

    /** Le club hôte rattaché au staff copié doit pointer vers le NOUVEAU taikai, pas l'ancien. */
    public function testCopiedDojoAdminStaffPointsToTheNewParticipatingDojo(): void
    {
        $taikai = $this->finishedTwoInOneTaikai(4);
        $participatingDojo = $taikai->getParticipatingDojos()->first();
        self::assertInstanceOf(ParticipatingDojo::class, $participatingDojo);

        $dojoAdmin = $this->createUser('dojo-admin@pikaichu.test');
        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::DojoAdmin))
            ->setUser($dojoAdmin)
            ->setParticipatingDojo($participatingDojo);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);
        $this->entityManager->flush();

        $newTaikai = $this->generationService->generateFromTwoInOne($taikai, $this->admin, 4);

        $copiedStaff = null;
        foreach ($newTaikai->getStaffs() as $candidate) {
            if ($candidate->getUser() === $dojoAdmin) {
                $copiedStaff = $candidate;
            }
        }

        self::assertNotNull($copiedStaff);
        self::assertNotNull($copiedStaff->getParticipatingDojo());
        self::assertSame($newTaikai, $copiedStaff->getParticipatingDojo()->getTaikai());
    }

    public function testRejectsGenerationWhenNotAllResultsAreFinalized(): void
    {
        $taikai = $this->twoInOneTaikaiInMarking(4);

        $this->expectException(TaikaiGenerationException::class);
        $this->generationService->generateFromTwoInOne($taikai, $this->admin, 4);
    }

    public function testRejectsGenerationWhenNotEnoughNonMixedTeams(): void
    {
        $taikai = $this->finishedTwoInOneTaikai(4, mixedTeams: 2);

        try {
            $this->generationService->generateFromTwoInOne($taikai, $this->admin, 4);
            self::fail('Expected a TaikaiGenerationException');
        } catch (TaikaiGenerationException $taikaiGenerationException) {
            self::assertSame('taikai.generate.not_enough_non_mixed_teams', $taikaiGenerationException->translationKey);
        }
    }

    public function testControllerRedirectsToTheNewTaikaiOnSuccess(): void
    {
        $taikai = $this->finishedTwoInOneTaikai(4);

        $this->client->request('GET', '/taikais/'.$taikai->getId());
        $token = $this->extractGenerateToken($taikai);

        $this->client->request('POST', '/taikais/'.$taikai->getId().'/generate', [
            '_token' => $token,
            'bracket_size' => '4',
        ]);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/taikais/\d+$#', $location);
        self::assertNotSame('/taikais/'.$taikai->getId(), $location);
    }

    /**
     * Simule une soumission tardive : le bouton n'était rendu que parce que le
     * taikai était encore 2-en-1 au moment du chargement de la page, mais sa
     * forme a changé depuis (jeton CSRF toujours valide, indépendant de l'état
     * du taikai).
     */
    public function testControllerRejectsWrongForm(): void
    {
        $taikai = $this->finishedTwoInOneTaikai(4);

        $this->client->request('GET', '/taikais/'.$taikai->getId());
        $token = $this->extractGenerateToken($taikai);

        $taikai->setForm(TaikaiForm::Individual);
        $this->entityManager->flush();

        $this->client->request('POST', '/taikais/'.$taikai->getId().'/generate', [
            '_token' => $token,
            'bracket_size' => '4',
        ]);

        self::assertResponseRedirects('/taikais/'.$taikai->getId());
        $this->client->followRedirect();
        self::assertSelectorExists('.notification.is-danger');
    }

    private function extractGenerateToken(Taikai $taikai): string
    {
        $content = (string) $this->client->getResponse()->getContent();
        $pattern = '#action="[^"]*/taikais/'.$taikai->getId().'/generate"[^>]*>\s*'
            .'<input type="hidden" name="_token" value="([^"]+)"#s';
        if (1 !== preg_match($pattern, $content, $matches)) {
            self::fail('Generate CSRF token not found in response.');
        }

        return $matches[1];
    }

    /** @return array{Taikai, ParticipatingDojo} */
    private function twoInOneContext(int $teamCount, int $mixedTeams = 0): array
    {
        $taikai = $this->createTaikai(TaikaiForm::TwoInOne);

        foreach ([StaffRoleCode::TaikaiAdmin, ...StaffRoleCode::requiredForMarking()] as $code) {
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

        for ($i = 1; $i <= $teamCount; ++$i) {
            $team = new Team();
            $team->setShortname('T'.$i)->setMixed($i <= $mixedTeams);
            $participatingDojo->addTeam($team);
            $this->entityManager->persist($team);

            $participant = new Participant();
            $participant->setFirstname('Archer'.$i)->setLastname('T'.$i)->setClub('nantes');
            $participatingDojo->addParticipant($participant);
            $team->addParticipant($participant);
            $this->entityManager->persist($participant);
        }

        $this->entityManager->flush();

        return [$taikai, $participatingDojo];
    }

    private function twoInOneTaikaiInMarking(int $teamCount, int $mixedTeams = 0): Taikai
    {
        [$taikai, $participatingDojo] = $this->twoInOneContext($teamCount, $mixedTeams);

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->admin);
        $this->drawService->draw($participatingDojo);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $this->admin);

        return $taikai;
    }

    private function finishedTwoInOneTaikai(int $teamCount, int $mixedTeams = 0): Taikai
    {
        $taikai = $this->twoInOneTaikaiInMarking($teamCount, $mixedTeams);

        foreach ($taikai->getParticipants() as $participant) {
            for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
                $this->markingService->addResult($participant, ResultStatus::Hit);
            }

            $this->markingService->finalizeRound($participant, 1);
        }

        $this->stateMachine->transitionTo($taikai, TaikaiState::TieBreak, $this->admin);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Done, $this->admin);

        // `commitSeeding()` détache toutes les entités déjà en mémoire, y
        // compris `$this->admin` : on le recharge pour ne pas le réutiliser
        // détaché dans les appels qui suivent (voir le piège identity map).
        $this->commitSeeding($this->entityManager);
        $taikai = $this->entityManager->getRepository(Taikai::class)->find($taikai->getId());
        self::assertInstanceOf(Taikai::class, $taikai);
        $this->admin = $this->entityManager->getRepository(User::class)->find($this->admin->getId());
        self::assertInstanceOf(User::class, $this->admin);

        return $taikai;
    }

    private function createTaikai(TaikaiForm $form): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm($form)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(4)
            ->setNumTargets(6)
            ->setTachiSize(1)
            ->setDistributed(false);
        $this->entityManager->persist($taikai);
        $this->entityManager->flush();

        return $taikai;
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

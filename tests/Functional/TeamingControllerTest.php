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
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Composition des équipes, portée de `teaming_controller`.
 *
 * L'affectation à une équipe se fait par sélection et place toujours le
 * participant en fin d'équipe (voir `TeamingService::moveParticipant()`) ;
 * le réordonnancement au sein d'une équipe déjà formée, lui, est bien porté
 * par glisser-déposer — voir `TeamController::reorderParticipant()` et
 * `assets/controllers/reorder_controller.js`.
 */
final class TeamingControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UserPasswordHasherInterface $passwordHasher;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

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

    public function testEditListsUnteamedParticipantsAndTeams(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->addTeam($participatingDojo, 'Équipe A');
        $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tanaka', $crawler->filter('body')->text());
        self::assertStringContainsString('Équipe A', $crawler->filter('body')->text());
    }

    public function testCanCreateTeamFromShortname(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));
        $token = $this->extractToken('create-team');

        $this->client->request('POST', $this->teamingPath($taikai, $participatingDojo).'/create-team', [
            '_token' => $token,
            'shortname' => 'Équipe A',
        ]);

        self::assertResponseRedirects($this->teamingPath($taikai, $participatingDojo));

        $teams = $this->entityManager->getRepository(Team::class)->findAll();
        self::assertCount(1, $teams);
        self::assertSame('Équipe A', $teams[0]->getShortname());
    }

    public function testCreatingTeamWithTakenShortnameFlashesAlert(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->addTeam($participatingDojo, 'Équipe A');

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));
        $token = $this->extractToken('create-team');

        $this->client->request('POST', $this->teamingPath($taikai, $participatingDojo).'/create-team', [
            '_token' => $token,
            'shortname' => 'équipe a',
        ]);

        self::assertResponseRedirects($this->teamingPath($taikai, $participatingDojo));
        self::assertCount(1, $this->entityManager->getRepository(Team::class)->findAll());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('existe déjà', $crawler->filter('body')->text());
    }

    public function testCanMoveParticipantIntoTeam(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $team = $this->addTeam($participatingDojo, 'Équipe A');
        $participant = $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka');

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));
        $token = $this->extractToken('move');

        $this->client->request('POST', $this->teamingPath($taikai, $participatingDojo).'/move', [
            '_token' => $token,
            'participant_id' => (string) $participant->getId(),
            'team_id' => (string) $team->getId(),
        ]);

        self::assertResponseRedirects($this->teamingPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Participant::class)->find($participant->getId());
        self::assertSame($team->getId(), $reloaded?->getTeam()?->getId());
        self::assertSame(1, $reloaded->getIndexInTeam());
    }

    public function testCanUnassignParticipantFromTeam(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $team = $this->addTeam($participatingDojo, 'Équipe A');
        $participant = $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka', $team);

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));
        $token = $this->extractToken('move');

        $this->client->request('POST', $this->teamingPath($taikai, $participatingDojo).'/move', [
            '_token' => $token,
            'participant_id' => (string) $participant->getId(),
            'team_id' => '',
        ]);

        self::assertResponseRedirects($this->teamingPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Participant::class)->find($participant->getId());
        self::assertNull($reloaded?->getTeam());
        self::assertNull($reloaded->getIndexInTeam());
    }

    public function testClearUnassignsAllParticipants(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $team = $this->addTeam($participatingDojo, 'Équipe A');
        $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka', $team);
        $this->addParticipant($participatingDojo, 'Haruki', 'Sato', $team);

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));
        $token = $this->extractToken('clear');

        $this->client->request('POST', $this->teamingPath($taikai, $participatingDojo).'/clear', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects($this->teamingPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Participant::class)->findAll() as $participant) {
            self::assertNull($participant->getTeam());
        }
    }

    /** Reprend `teaming#form_randomly` : équipes mixtes de la taille d'un tachi. */
    public function testFormsTeamsRandomly(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        for ($i = 1; $i <= 4; ++$i) {
            $this->addParticipant($participatingDojo, 'Archer'.$i, 'Test');
        }

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));
        $token = $this->extractToken('form-randomly');

        $this->client->request('POST', $this->teamingPath($taikai, $participatingDojo).'/form-randomly', [
            '_token' => $token,
            'prefix' => 'E',
        ]);

        self::assertResponseRedirects($this->teamingPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        $teams = $this->entityManager->getRepository(Team::class)->findAll();
        // Taikai::tachiSize=3, donc 4 participants font 2 équipes (3 puis 1).
        self::assertCount(2, $teams);
        foreach ($teams as $team) {
            self::assertTrue($team->isMixed());
            self::assertStringStartsWith('E', (string) $team->getShortname());
        }

        $totalParticipants = array_sum(array_map(static fn (Team $t): int => $t->getParticipants()->count(), $teams));
        self::assertSame(4, $totalParticipants);
    }

    /**
     * Les trois participants ont déjà un `indexInTeam` distinct avant le
     * déplacement (contrairement à des participants tout juste affectés,
     * encore à `null`) : c'est ce qui expose la collision transitoire sur
     * l'index unique partiel `(team_id, index_in_team)` (voir `TeamingService`).
     */
    public function testCanReorderParticipantWithinTeam(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $team = $this->addTeam($participatingDojo, 'Équipe A');
        $first = $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka', $team);
        $second = $this->addParticipant($participatingDojo, 'Haruki', 'Sato', $team);
        $third = $this->addParticipant($participatingDojo, 'Ren', 'Ito', $team);
        $first->setIndexInTeam(1);
        $second->setIndexInTeam(2);
        $third->setIndexInTeam(3);
        $this->entityManager->flush();

        $this->commitSeeding($this->entityManager);

        // Le dernier de la liste passe en tête : chaque autre participant
        // doit décaler son index d'un cran.
        $crawler = $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));
        $token = $this->extractReorderToken($crawler, $third);

        $this->client->request('POST', $this->reorderPath($taikai, $participatingDojo, $team, $third), [
            '_token' => $token,
            'index' => '1',
        ]);

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloadedFirst = $this->entityManager->getRepository(Participant::class)->find($first->getId());
        $reloadedSecond = $this->entityManager->getRepository(Participant::class)->find($second->getId());
        $reloadedThird = $this->entityManager->getRepository(Participant::class)->find($third->getId());
        self::assertSame(1, $reloadedThird?->getIndexInTeam());
        self::assertSame(2, $reloadedFirst?->getIndexInTeam());
        self::assertSame(3, $reloadedSecond?->getIndexInTeam());
    }

    public function testReorderRejectsAnInvalidCsrfToken(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $team = $this->addTeam($participatingDojo, 'Équipe A');
        $participant = $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka', $team);

        $this->commitSeeding($this->entityManager);

        $this->client->request('POST', $this->reorderPath($taikai, $participatingDojo, $team, $participant), [
            '_token' => 'not-a-valid-token',
            'index' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** Sans droit sur le club hôte, l'écran est refusé. */
    public function testPlainUserCannotAccess(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));

        $this->client->request('GET', $this->teamingPath($taikai, $participatingDojo));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le jeton est désormais scopé au `<form>` visé : la page inclut aussi la
     * frise d'avancement du taikai (gabarit `taikai/_layout.html.twig`), avec
     * ses propres formulaires de transition et donc ses propres jetons.
     */
    private function extractToken(string $actionContains): string
    {
        $crawler = $this->client->getCrawler();
        $form = $crawler->filter('form[action*="'.$actionContains.'"]');
        self::assertGreaterThan(0, $form->count(), 'form not found for action containing "'.$actionContains.'"');

        $token = $form->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    private function extractReorderToken(Crawler $crawler, Participant $participant): string
    {
        $node = $crawler->filter('[data-reorder-id="'.$participant->getId().'"]');
        self::assertGreaterThan(0, $node->count(), 'reorder row not found for participant '.$participant->getId());

        $token = $node->attr('data-reorder-token');
        self::assertNotNull($token);

        return $token;
    }

    private function reorderPath(Taikai $taikai, ParticipatingDojo $participatingDojo, Team $team, Participant $participant): string
    {
        return \sprintf(
            '/taikais/%d/participating-dojos/%d/teams/%d/participants/%d/reorder',
            $taikai->getId(),
            $participatingDojo->getId(),
            $team->getId(),
            $participant->getId(),
        );
    }

    private function teamingPath(Taikai $taikai, ParticipatingDojo $participatingDojo): string
    {
        return \sprintf(
            '/taikais/%d/participating-dojos/%d/teaming',
            $taikai->getId(),
            $participatingDojo->getId(),
        );
    }

    /** @return array{Taikai, ParticipatingDojo} */
    private function createContext(): array
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Team)
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

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

        $this->entityManager->flush();

        return [$taikai, $participatingDojo];
    }

    private function addTeam(ParticipatingDojo $participatingDojo, string $shortname): Team
    {
        $team = new Team();
        $team->setShortname($shortname);

        $participatingDojo->addTeam($team);

        $this->entityManager->persist($team);
        $this->entityManager->flush();

        return $team;
    }

    private function addParticipant(ParticipatingDojo $participatingDojo, string $firstname, string $lastname, ?Team $team = null): Participant
    {
        $participant = new Participant();
        $participant->setFirstname($firstname)
            ->setLastname($lastname)
            ->setClub('nantes');
        $participatingDojo->addParticipant($participant);
        $team?->addParticipant($participant);

        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant;
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

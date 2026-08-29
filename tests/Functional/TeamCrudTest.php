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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CRUD des équipes d'un club hôte, porté de `teams_controller`.
 *
 * `teams_controller` n'appelle jamais `authorize` côté Rails ; le portage
 * réserve ces actions à `ParticipatingDojoVoter::EDIT` (voir `MIGRATION.md`).
 */
final class TeamCrudTest extends WebTestCase
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

        $this->loadStaffRoles();

        $this->admin = $this->createUser('admin@pikaichu.test');
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testCanCreateTeam(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->teamsPath($taikai, $participatingDojo).'/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="team"]')->form([
            'team[shortname]' => 'Équipe A',
        ]));

        self::assertResponseRedirects($this->hostClubPath($taikai, $participatingDojo));

        $team = $this->findTeam();
        self::assertSame('Équipe A', $team->getShortname());
    }

    public function testRejectsBlankShortname(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->teamsPath($taikai, $participatingDojo).'/new');

        $this->client->submit($crawler->filter('form[name="team"]')->form([
            'team[shortname]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(Team::class)->findAll());
    }

    /** Rails compare les noms insensiblement à la casse au sein d'un même club hôte. */
    public function testRejectsShortnameAlreadyUsedCaseInsensitively(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->addTeam($participatingDojo, 'Équipe A');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->teamsPath($taikai, $participatingDojo).'/new');

        $this->client->submit($crawler->filter('form[name="team"]')->form([
            'team[shortname]' => 'équipe a',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager->getRepository(Team::class)->findAll());
    }

    public function testCanEditTeam(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $team = $this->addTeam($participatingDojo, 'Équipe A');
        $id = $team->getId();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->teamsPath($taikai, $participatingDojo).'/'.$id.'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="team"]')->form([
            'team[shortname]' => 'Équipe B',
        ]));

        self::assertResponseRedirects($this->hostClubPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Team::class)->find($id);
        self::assertSame('Équipe B', $reloaded?->getShortname());
    }

    /**
     * Rails détruit les participants de l'équipe en même temps qu'elle
     * (`dependent: :destroy`) — voir la migration `Version20260827143052`.
     */
    public function testDeletingTeamAlsoDeletesItsParticipants(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $team = $this->addTeam($participatingDojo, 'Équipe A');
        $participant = $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka', $team);
        $teamId = $team->getId();
        $participantId = $participant->getId();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->hostClubPath($taikai, $participatingDojo));
        $action = $this->teamsPath($taikai, $participatingDojo).'/'.$teamId;

        $this->client->submit($crawler->filter('form[action="'.$action.'"]')->form());

        self::assertResponseRedirects($this->hostClubPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Team::class)->find($teamId));
        self::assertNull($this->entityManager->getRepository(Participant::class)->find($participantId));
    }

    /** Sans droit sur le club hôte, la saisie est refusée. */
    public function testPlainUserCannotCreateTeam(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));

        $this->client->request('GET', $this->teamsPath($taikai, $participatingDojo).'/new');

        self::assertResponseStatusCodeSame(403);
    }

    private function teamsPath(Taikai $taikai, ParticipatingDojo $participatingDojo): string
    {
        return \sprintf(
            '/taikais/%d/participating-dojos/%d/teams',
            $taikai->getId(),
            $participatingDojo->getId(),
        );
    }

    private function hostClubPath(Taikai $taikai, ParticipatingDojo $participatingDojo): string
    {
        return \sprintf(
            '/taikais/%d/participating-dojos/%d/edit',
            $taikai->getId(),
            $participatingDojo->getId(),
        );
    }

    private function findTeam(): Team
    {
        $this->entityManager->clear();
        $teams = $this->entityManager->getRepository(Team::class)->findAll();
        self::assertCount(1, $teams);

        return $teams[0];
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

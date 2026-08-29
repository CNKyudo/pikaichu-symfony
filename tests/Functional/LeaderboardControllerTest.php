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
 * Tableaux de résultats, portés de `leaderboard_controller`.
 *
 * Pour un 2-en-1, `show` affiche équipes et individuel ensemble (un choix
 * délibéré, plus riche que les deux écrans séparés de Rails) ; en revanche
 * `public`, destiné à la projection en salle, reprend le bascule Rails —
 * équipes par défaut, individuel si `?individual` est présent — puisque
 * superposer les deux serait illisible sur un écran de projection.
 */
final class LeaderboardControllerTest extends WebTestCase
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

    public function testShowCombinesTeamAndIndividualForTwoInOne(): void
    {
        $taikai = $this->createTwoInOneContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/leaderboard');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Équipe A', $text);
        self::assertStringContainsString('Yumi Tanaka', $text);
    }

    /**
     * Le tableau par équipes détaille chaque flèche par participant (comme
     * `leaderboard/_team.html.erb` côté Rails) : "Yumi Tanaka" y figure donc
     * aussi. Le signal distinctif du mode équipe est la colonne "Total".
     */
    public function testPublicViewShowsTeamOnlyByDefaultForTwoInOne(): void
    {
        $taikai = $this->createTwoInOneContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/leaderboard/public');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Équipe A', $text);
        self::assertStringContainsString('Total', $text);
    }

    public function testPublicViewShowsIndividualOnlyWhenRequestedForTwoInOne(): void
    {
        $taikai = $this->createTwoInOneContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/leaderboard/public?individual=1');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringNotContainsString('Équipe A', $text);
        self::assertStringContainsString('Yumi Tanaka', $text);
    }

    /** La projection publique n'exige pas d'authentification, comme côté Rails. */
    public function testPublicViewDoesNotRequireAuthentication(): void
    {
        $taikai = $this->createTwoInOneContext();
        $this->commitSeeding($this->entityManager);

        static::ensureKernelShutdown();

        $anonymousClient = static::createClient();
        $anonymousClient->request('GET', '/taikais/'.$taikai->getId().'/leaderboard/public');

        self::assertResponseIsSuccessful();
    }

    /** Détail par flèche, donc participants inclus (voir le test `public` ci-dessus). */
    public function testTeamOnlyRouteShowsTeamTable(): void
    {
        $taikai = $this->createTwoInOneContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/leaderboard/2in1');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Équipe A', $text);
        self::assertStringContainsString('Total', $text);
    }

    public function testTeamOnlyRouteRequiresAuthentication(): void
    {
        $taikai = $this->createTwoInOneContext();
        $this->commitSeeding($this->entityManager);

        static::ensureKernelShutdown();

        $anonymousClient = static::createClient();
        $anonymousClient->request('GET', '/taikais/'.$taikai->getId().'/leaderboard/2in1');

        self::assertResponseRedirects('/login');
    }

    private function createTwoInOneContext(): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::TwoInOne)
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

        $team = new Team();
        $team->setShortname('Équipe A');

        $participatingDojo->addTeam($team);
        $this->entityManager->persist($team);

        $participant = new Participant();
        $participant->setFirstname('Yumi')->setLastname('Tanaka')->setClub('nantes');
        $participatingDojo->addParticipant($participant);
        $team->addParticipant($participant);
        $this->entityManager->persist($participant);

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

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

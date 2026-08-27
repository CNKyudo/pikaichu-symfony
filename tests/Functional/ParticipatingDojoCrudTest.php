<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
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
 * CRUD des clubs hôtes, porté de `participating_dojos_controller`.
 *
 * Rails renvoie vers `taikais#edit` après chaque écriture parce que cette page y
 * embarque la liste des clubs hôtes ; dans ce portage la liste vit sur la vue
 * d'ensemble du taikai, c'est donc elle qui sert de cible.
 */
final class ParticipatingDojoCrudTest extends WebTestCase
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

    /** Sans nom d'affichage, Rails retombe sur le nom court du club. */
    public function testCreateFallsBackOnDojoShortnameAsDisplayName(): void
    {
        $taikai = $this->createTaikai(distributed: true);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $dojo = $this->createDojo('nantes');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/participating-dojos/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="participating_dojo"]')->form([
            'participating_dojo[dojo]' => (string) $dojo->getId(),
            'participating_dojo[displayName]' => '',
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId());

        $participatingDojo = $this->findParticipatingDojo($taikai);
        self::assertSame('nantes', $participatingDojo->getDisplayName());
    }

    public function testCreateKeepsExplicitDisplayName(): void
    {
        $taikai = $this->createTaikai(distributed: true);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $dojo = $this->createDojo('rennes');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/participating-dojos/new');

        $this->client->submit($crawler->filter('form[name="participating_dojo"]')->form([
            'participating_dojo[dojo]' => (string) $dojo->getId(),
            'participating_dojo[displayName]' => 'Club organisateur',
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId());
        self::assertSame('Club organisateur', $this->findParticipatingDojo($taikai)->getDisplayName());
    }

    public function testCanEditDisplayName(): void
    {
        $taikai = $this->createTaikai(distributed: true);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $participatingDojo = $this->addParticipatingDojo($taikai, $this->createDojo('brest'));

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request(
            'GET',
            '/taikais/'.$taikai->getId().'/participating-dojos/'.$participatingDojo->getId().'/edit',
        );
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="participating_dojo"]')->form([
            'participating_dojo[displayName]' => 'Brest Kyudo',
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId());

        $this->entityManager->clear();
        self::assertSame('Brest Kyudo', $this->findParticipatingDojo($taikai)->getDisplayName());
    }

    /**
     * Un taikai sur place n'accepte qu'un seul club hôte : Rails grise le bouton
     * et le modèle refuse l'enregistrement (`number_of_dojos`).
     */
    public function testCannotAddSecondHostClubToNonDistributedTaikai(): void
    {
        $taikai = $this->createTaikai(distributed: false);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $this->addParticipatingDojo($taikai, $this->createDojo('nantes'));
        $second = $this->createDojo('rennes');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/participating-dojos/new');

        $this->client->submit($crawler->filter('form[name="participating_dojo"]')->form([
            'participating_dojo[dojo]' => (string) $second->getId(),
            'participating_dojo[displayName]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(ParticipatingDojo::class)->findAll());
    }

    public function testCanDeleteHostClubWithoutStaff(): void
    {
        $taikai = $this->createTaikai(distributed: true);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $participatingDojo = $this->addParticipatingDojo($taikai, $this->createDojo('lorient'));
        $id = $participatingDojo->getId();

        $this->commitSeeding($this->entityManager);
        $this->submitDeleteForm($taikai, $id);

        self::assertResponseRedirects('/taikais/'.$taikai->getId());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(ParticipatingDojo::class)->find($id));
    }

    /**
     * Rails refuse la suppression d'un club hôte rattaché à des membres du staff
     * et le signale en nommant les personnes concernées.
     */
    public function testCannotDeleteHostClubStillLinkedToStaff(): void
    {
        $taikai = $this->createTaikai(distributed: true);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $participatingDojo = $this->addParticipatingDojo($taikai, $this->createDojo('vannes'));
        $id = $participatingDojo->getId();

        $dojoAdmin = $this->createUser('dojo-admin@pikaichu.test');
        $this->addStaff($taikai, $dojoAdmin, StaffRoleCode::DojoAdmin, $participatingDojo);

        $this->commitSeeding($this->entityManager);
        $this->submitDeleteForm($taikai, $id);

        self::assertResponseRedirects('/taikais/'.$taikai->getId());
        $this->client->followRedirect();
        self::assertSelectorExists('.notification.is-danger');

        $this->entityManager->clear();
        self::assertInstanceOf(
            ParticipatingDojo::class,
            $this->entityManager->getRepository(ParticipatingDojo::class)->find($id),
        );
    }

    /** Sans rôle sur le taikai, l'ajout d'un club hôte est refusé. */
    public function testPlainUserCannotCreateHostClub(): void
    {
        $taikai = $this->createTaikai(distributed: true);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $this->createDojo('nantes');

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));
        $this->client->request('GET', '/taikais/'.$taikai->getId().'/participating-dojos/new');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Rails autorise en plus l'administrateur du club hôte (`dojo_admin`) à
     * modifier sa propre ligne, sans lui ouvrir la suppression.
     */
    public function testDojoAdminCanEditOwnHostClubButNotDeleteIt(): void
    {
        $taikai = $this->createTaikai(distributed: true);
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $participatingDojo = $this->addParticipatingDojo($taikai, $this->createDojo('vannes'));

        $dojoAdmin = $this->createUser('dojo-admin@pikaichu.test');
        $this->addStaff($taikai, $dojoAdmin, StaffRoleCode::DojoAdmin, $participatingDojo);

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($dojoAdmin);

        $this->client->request(
            'GET',
            '/taikais/'.$taikai->getId().'/participating-dojos/'.$participatingDojo->getId().'/edit',
        );
        self::assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/taikais/'.$taikai->getId().'/participating-dojos/'.$participatingDojo->getId(),
        );
        self::assertResponseStatusCodeSame(403);
    }

    private function submitDeleteForm(Taikai $taikai, ?int $participatingDojoId): void
    {
        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId());
        $action = '/taikais/'.$taikai->getId().'/participating-dojos/'.$participatingDojoId;

        $this->client->submit($crawler->filter('form[action="'.$action.'"]')->form());
    }

    private function findParticipatingDojo(Taikai $taikai): ParticipatingDojo
    {
        $participatingDojo = $this->entityManager->getRepository(ParticipatingDojo::class)
            ->findOneBy(['taikai' => $taikai->getId()]);

        self::assertInstanceOf(ParticipatingDojo::class, $participatingDojo);

        return $participatingDojo;
    }

    private function createTaikai(bool $distributed): Taikai
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
            ->setDistributed($distributed);

        $this->entityManager->persist($taikai);
        $this->entityManager->flush();

        return $taikai;
    }

    private function createDojo(string $shortname): Dojo
    {
        $dojo = new Dojo();
        $dojo->setShortname($shortname)
            ->setName('Club '.$shortname)
            ->setCity('Ville')
            ->setCountryCode('FR');

        $this->entityManager->persist($dojo);
        $this->entityManager->flush();

        return $dojo;
    }

    private function addParticipatingDojo(Taikai $taikai, Dojo $dojo): ParticipatingDojo
    {
        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName((string) $dojo->getShortname());
        $taikai->addParticipatingDojo($participatingDojo);

        $this->entityManager->persist($participatingDojo);
        $this->entityManager->flush();

        return $participatingDojo;
    }

    private function makeTaikaiAdmin(Taikai $taikai, User $user): void
    {
        $this->addStaff($taikai, $user, StaffRoleCode::TaikaiAdmin);
    }

    private function addStaff(
        Taikai $taikai,
        User $user,
        StaffRoleCode $code,
        ?ParticipatingDojo $participatingDojo = null,
    ): void {
        $staff = new Staff();
        $staff->setRole($this->findRole($code))
            ->setUser($user)
            ->setParticipatingDojo($participatingDojo);
        $taikai->addStaff($staff);

        $this->entityManager->persist($staff);
        $this->entityManager->flush();
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

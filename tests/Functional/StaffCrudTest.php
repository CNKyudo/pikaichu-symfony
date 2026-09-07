<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\TaikaiTransition;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CRUD du staff d'un taikai, porté de `staffs_controller`.
 *
 * Rails n'y applique aucune autorisation Pundit : n'importe quel utilisateur
 * authentifié pourrait s'auto-nommer administrateur d'un taikai qui ne lui
 * appartient pas. C'est une élévation de privilèges, pas un choix voulu — le
 * portage réserve donc ces actions à `TAIKAI_EDIT`, comme le reste des écrans
 * d'administration du taikai.
 */
final class StaffCrudTest extends WebTestCase
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

    /** Un rôle sans exigence particulière (chairman) se crée avec une identité saisie à la main. */
    public function testCanCreateStaffWithTypedIdentity(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->newPath($taikai));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="staff"]')->form([
            'staff[firstname]' => 'Kenji',
            'staff[lastname]' => 'Takahashi',
            'staff[role]' => (string) $this->findRole(StaffRoleCode::Chairman)->getId(),
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/edit');

        $staff = $this->findStaffByLastname('Takahashi');
        self::assertSame('Kenji', $staff->getFirstname());
        self::assertNull($staff->getUser());
    }

    /** Sélectionner un utilisateur écrase le nom saisi, comme le `before_validation` Rails. */
    public function testSelectingAUserOverridesTypedIdentity(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $referee = $this->createUser('referee@pikaichu.test', 'Haruki', 'Sato');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->newPath($taikai));

        $this->submitStaffForm($crawler, [
            'staff[user]' => (string) $referee->getId(),
            'staff[firstname]' => 'Saisie',
            'staff[lastname]' => 'Ignoree',
            'staff[role]' => (string) $this->findRole(StaffRoleCode::Chairman)->getId(),
        ]);

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/edit');

        $staff = $this->findStaffByLastname('Sato');
        self::assertSame('Haruki', $staff->getFirstname());
        self::assertSame($referee->getId(), $staff->getUser()?->getId());
    }

    /** `dojo_admin` exige un compte utilisateur. */
    public function testRejectsDojoAdminWithoutUser(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $participatingDojo = $this->addParticipatingDojo($taikai);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->newPath($taikai));

        $this->client->submit($crawler->filter('form[name="staff"]')->form([
            'staff[firstname]' => 'Sans',
            'staff[lastname]' => 'Compte',
            'staff[role]' => (string) $this->findRole(StaffRoleCode::DojoAdmin)->getId(),
            'staff[participatingDojo]' => (string) $participatingDojo->getId(),
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager->getRepository(Staff::class)->findAll());
    }

    /** `dojo_admin` exige aussi un club hôte. */
    public function testRejectsDojoAdminWithoutParticipatingDojo(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $referee = $this->createUser('referee@pikaichu.test', 'Haruki', 'Sato');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->newPath($taikai));

        $this->submitStaffForm($crawler, [
            'staff[user]' => (string) $referee->getId(),
            'staff[firstname]' => 'x',
            'staff[lastname]' => 'y',
            'staff[role]' => (string) $this->findRole(StaffRoleCode::DojoAdmin)->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager->getRepository(Staff::class)->findAll());
    }

    /**
     * Un taikai à l'étape « Terminé » est figé (`no_change_if_taikai_is_done`
     * côté Rails, appliqué à Staff comme à cinq autres modèles).
     */
    public function testCannotEditStaffOnceTaikaiIsDone(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $staff = $this->addStaff($taikai, StaffRoleCode::Chairman, 'Ancien', 'Nom');
        $this->markTaikaiDone($taikai);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai, $staff));

        $this->client->submit($crawler->filter('form[name="staff"]')->form([
            'staff[firstname]' => 'Nouveau',
        ]));

        self::assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Staff::class)->find($staff->getId());
        self::assertSame('Ancien', $reloaded?->getFirstname());
    }

    public function testCanEditStaff(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $staff = $this->addStaff($taikai, StaffRoleCode::Chairman, 'Ancien', 'Nom');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai, $staff));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="staff"]')->form([
            'staff[firstname]' => 'Nouveau',
            'staff[lastname]' => 'Nom',
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/edit');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Staff::class)->find($staff->getId());
        self::assertSame('Nouveau', $reloaded?->getFirstname());
    }

    public function testCanDeleteStaff(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $staff = $this->addStaff($taikai, StaffRoleCode::Chairman, 'Kenji', 'Takahashi');
        $id = $staff->getId();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/edit');
        $this->client->submit($crawler->filter('form[action="'.$this->deletePath($taikai, $id).'"]')->form());

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/edit');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Staff::class)->find($id));
    }

    /**
     * Le taikai doit conserver au moins un administrateur : ni la suppression du
     * dernier ni la rétrogradation de son rôle ne sont permises.
     */
    public function testCannotDeleteLastTaikaiAdmin(): void
    {
        $taikai = $this->createTaikai();
        $staff = $this->makeTaikaiAdmin($taikai, $this->admin);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/edit');
        $this->client->submit($crawler->filter('form[action="'.$this->deletePath($taikai, $staff->getId()).'"]')->form());

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/edit');
        $this->client->followRedirect();
        self::assertSelectorExists('.notification.is-danger');

        $this->entityManager->clear();
        self::assertInstanceOf(Staff::class, $this->entityManager->getRepository(Staff::class)->find($staff->getId()));
    }

    public function testCannotDemoteLastTaikaiAdmin(): void
    {
        $taikai = $this->createTaikai();
        $staff = $this->makeTaikaiAdmin($taikai, $this->admin);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai, $staff));

        $this->client->submit($crawler->filter('form[name="staff"]')->form([
            'staff[role]' => (string) $this->findRole(StaffRoleCode::Chairman)->getId(),
        ]));

        self::assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Staff::class)->find($staff->getId());
        self::assertSame(StaffRoleCode::TaikaiAdmin, $reloaded?->getRole()?->getCode());
    }

    /** Un deuxième administrateur peut librement être retiré. */
    public function testCanDeleteAdminWhenAnotherRemains(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);
        $second = $this->createUser('second-admin@pikaichu.test', 'Second', 'Admin');
        $secondStaff = $this->addStaff($taikai, StaffRoleCode::TaikaiAdmin, 'Second', 'Admin', $second);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/edit');
        $this->client->submit($crawler->filter('form[action="'.$this->deletePath($taikai, $secondStaff->getId()).'"]')->form());

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/edit');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Staff::class)->find($secondStaff->getId()));
    }

    /** Sans droit sur le taikai, la gestion du staff est refusée. */
    public function testPlainUserCannotManageStaff(): void
    {
        $taikai = $this->createTaikai();
        $this->makeTaikaiAdmin($taikai, $this->admin);

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));

        $this->client->request('GET', $this->newPath($taikai));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Soumet le formulaire de staff, en passant outre la validation de
     * `staff[user]` : ce champ est désormais une recherche AJAX (TomSelect)
     * dont le crawler ne connaît pas les <option> chargées côté client.
     *
     * @param array<string, string> $fields
     */
    private function submitStaffForm(Crawler $crawler, array $fields): void
    {
        $form = $crawler->filter('form[name="staff"]')->form();

        if (\array_key_exists('staff[user]', $fields)) {
            $userField = $form->get('staff[user]');
            self::assertInstanceOf(ChoiceFormField::class, $userField);
            $userField->disableValidation()->setValue($fields['staff[user]']);
            unset($fields['staff[user]']);
        }

        $form->setValues($fields);

        $this->client->submit($form);
    }

    private function newPath(Taikai $taikai): string
    {
        return '/taikais/'.$taikai->getId().'/staffs/new';
    }

    private function editPath(Taikai $taikai, Staff $staff): string
    {
        return '/taikais/'.$taikai->getId().'/staffs/'.$staff->getId().'/edit';
    }

    private function deletePath(Taikai $taikai, ?int $staffId): string
    {
        return '/taikais/'.$taikai->getId().'/staffs/'.$staffId;
    }

    private function findStaffByLastname(string $lastname): Staff
    {
        $this->entityManager->clear();
        $staff = $this->entityManager->getRepository(Staff::class)->findOneBy(['lastname' => $lastname]);
        self::assertInstanceOf(Staff::class, $staff);

        return $staff;
    }

    private function createTaikai(): Taikai
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
        $this->entityManager->flush();

        return $taikai;
    }

    private function addParticipatingDojo(Taikai $taikai): ParticipatingDojo
    {
        $dojo = new Dojo();
        $dojo->setShortname('ktlg')->setName('Club test')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('ktlg');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);
        $this->entityManager->flush();

        return $participatingDojo;
    }

    /** Ajoute directement une transition vers « Terminé », sans repasser par la machine à états. */
    private function markTaikaiDone(Taikai $taikai): void
    {
        $transition = new TaikaiTransition();
        $transition->setToState(TaikaiState::Done)->setSortKey(1)->setMostRecent(true);
        $taikai->addTransition($transition);

        $this->entityManager->persist($transition);
        $this->entityManager->flush();
    }

    private function makeTaikaiAdmin(Taikai $taikai, User $user): Staff
    {
        return $this->addStaff($taikai, StaffRoleCode::TaikaiAdmin, $user->getFirstname() ?? '', $user->getLastname() ?? '', $user);
    }

    private function addStaff(
        Taikai $taikai,
        StaffRoleCode $code,
        string $firstname,
        string $lastname,
        ?User $user = null,
    ): Staff {
        $staff = new Staff();
        $staff->setRole($this->findRole($code))
            ->setFirstname($firstname)
            ->setLastname($lastname);
        if (null !== $user) {
            $staff->setUser($user);
        }

        $taikai->addStaff($staff);

        $this->entityManager->persist($staff);
        $this->entityManager->flush();

        return $staff;
    }

    private function createUser(string $email, string $firstname = 'Test', string $lastname = 'User'): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname($firstname)
            ->setLastname($lastname)
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

<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
 * CRUD de base d'un taikai, porté de `taikais_controller#new/create/edit/update/destroy`.
 *
 * `TaikaiWorkflowTest` couvre déjà la machine à états en profondeur au niveau
 * service ; ce test-ci couvre seulement le passage par les écrans HTTP
 * (formulaires, permissions), qui n'avait pas d'équivalent fonctionnel.
 */
final class TaikaiCrudTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->loadStaffRoles();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    /** N'importe quel utilisateur connecté peut créer un taikai : il en devient administrateur. */
    public function testAnyUserCanCreateTaikai(): void
    {
        $container = static::getContainer();
        $this->client->loginUser($this->createUser($container->get(UserPasswordHasherInterface::class), 'creator@pikaichu.test'));

        $crawler = $this->client->request('GET', '/taikais/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="taikai"]')->form([
            'taikai[shortname]' => 'test-taikai',
            'taikai[name]' => 'Taikai de test',
            'taikai[startDate]' => '2026-09-01',
            'taikai[endDate]' => '2026-09-01',
            'taikai[scoring]' => TaikaiScoring::Kinteki->value,
            'taikai[form]' => TaikaiForm::Individual->value,
            'taikai[totalNumArrows]' => '12',
            'taikai[numTargets]' => '6',
            'taikai[tachiSize]' => '3',
        ]));

        self::assertResponseRedirects();

        $taikai = $this->entityManager->getRepository(Taikai::class)->findOneBy(['shortname' => 'test-taikai']);
        self::assertInstanceOf(Taikai::class, $taikai);
        self::assertCount(1, $taikai->getStaffs());
    }

    public function testCanEditTaikai(): void
    {
        $container = static::getContainer();
        $editor = $this->createUser($container->get(UserPasswordHasherInterface::class), 'editor@pikaichu.test');
        $this->client->loginUser($editor);
        $taikai = $this->createTaikai('editable');
        $this->makeTaikaiAdmin($taikai, $editor);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="taikai"]')->form([
            'taikai[shortname]' => 'editable',
            'taikai[name]' => 'Nouveau nom',
            'taikai[startDate]' => '2026-09-01',
            'taikai[endDate]' => '2026-09-01',
            'taikai[scoring]' => TaikaiScoring::Kinteki->value,
            'taikai[form]' => TaikaiForm::Individual->value,
            'taikai[totalNumArrows]' => '12',
            'taikai[numTargets]' => '6',
            'taikai[tachiSize]' => '3',
        ]));

        self::assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Taikai::class)->find($taikai->getId());
        self::assertSame('Nouveau nom', $reloaded->getName());
    }

    /** Le bouton de suppression, accessible depuis la page de détail, doit fonctionner de bout en bout. */
    public function testCanDeleteTaikaiFromShowPage(): void
    {
        $container = static::getContainer();
        $deleter = $this->createUser($container->get(UserPasswordHasherInterface::class), 'deleter@pikaichu.test');
        $this->client->loginUser($deleter);
        $taikai = $this->createTaikai('deletable');
        $this->makeTaikaiAdmin($taikai, $deleter);
        $id = $taikai->getId();

        $crawler = $this->client->request('GET', '/taikais/'.$id);
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[action="/taikais/'.$id.'"]')->form());

        self::assertResponseRedirects('/taikais');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Taikai::class)->find($id));
    }

    /** Rails : `destroy?` == `admin?`, la même règle que pour `update?`. */
    public function testPlainUserCannotDeleteTaikai(): void
    {
        $container = static::getContainer();
        $taikai = $this->createTaikai('protected');
        $this->client->loginUser($this->createUser($container->get(UserPasswordHasherInterface::class), 'outsider@pikaichu.test'));

        $this->client->request('POST', '/taikais/'.$taikai->getId(), [
            '_token' => 'invalid-or-irrelevant',
        ]);

        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        self::assertInstanceOf(Taikai::class, $this->entityManager->getRepository(Taikai::class)->find($taikai->getId()));
    }

    private function makeTaikaiAdmin(Taikai $taikai, User $user): void
    {
        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($user);
        $taikai->addStaff($staff);

        $this->entityManager->persist($staff);
        $this->entityManager->flush();
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

    private function createTaikai(string $shortname): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname($shortname)
            ->setName('Taikai '.$shortname)
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(12)
            ->setNumTargets(6)
            ->setTachiSize(3)
            ->setDistributed(false);

        $this->entityManager->persist($taikai);
        $this->entityManager->flush();

        return $taikai;
    }

    private function createUser(UserPasswordHasherInterface $hasher, string $email): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Taikai')
            ->setLastname('Tester')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Kyudojin;
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
 * Autocomplétion, portée de `search_controller`.
 *
 * Rails renvoie un fragment HTML pour un contrôleur Stimulus non porté (voir
 * `SearchController`) : ces points d'entrée renvoient du JSON à la place.
 */
final class SearchControllerTest extends WebTestCase
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

    public function testKyudojinsSearchMatchesByName(): void
    {
        $this->createKyudojin('Haruki', 'Sato', 'ktlg');
        $this->createKyudojin('Yumi', 'Tanaka', 'ktlg');

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/kyudojins/available?q=sato');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('Haruki Sato', $data[0]['label']);
    }

    public function testKyudojinsSearchRequiresAuthentication(): void
    {
        $this->commitSeeding($this->entityManager);

        static::ensureKernelShutdown();

        $anonymousClient = static::createClient();
        $anonymousClient->request('GET', '/kyudojins/available?q=sato');

        self::assertResponseRedirects('/login');
    }

    /** Un utilisateur déjà membre du staff n'est pas proposé une seconde fois. */
    public function testStaffUsersExcludesAlreadyStaffedUsers(): void
    {
        $taikai = $this->createTaikai();
        $alreadyStaffed = $this->createUser('deja-staff@pikaichu.test', 'Deja', 'Staff');
        $available = $this->createUser('disponible@pikaichu.test', 'Dispo', 'Nible');

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($alreadyStaffed);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);
        $this->entityManager->flush();

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/staffs/available-users?query=a');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $ids = array_column($data['results'], 'id');
        self::assertNotContains($alreadyStaffed->getId(), $ids);
        self::assertContains($available->getId(), $ids);
    }

    /** Éditer un membre du staff existant garde son propre utilisateur dans la liste. */
    public function testStaffUsersKeepsCurrentlyEditedStaffUser(): void
    {
        $taikai = $this->createTaikai();
        $user = $this->createUser('actuel@pikaichu.test', 'Actuel', 'User');

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($user);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);
        $this->entityManager->flush();

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/staffs/available-users?query=actuel&staffId='.$staff->getId());

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $ids = array_column($data['results'], 'id');
        self::assertContains($user->getId(), $ids);
    }

    /** Un club déjà club hôte du taikai n'est pas reproposé. */
    public function testParticipatingDojoDojosExcludesAlreadyPresentDojos(): void
    {
        $taikai = $this->createTaikai();
        $alreadyPresent = $this->createDojo('ktlg', 'Kyudo Traditionnel');
        $available = $this->createDojo('akvm', 'Association Kyudo');

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($alreadyPresent)->setDisplayName('ktlg');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);
        $this->entityManager->flush();

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/participating-dojos/available-dojos?q=k');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $ids = array_column($data, 'id');
        self::assertNotContains($alreadyPresent->getId(), $ids);
        self::assertContains($available->getId(), $ids);
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
            ->setDistributed(true);
        $this->entityManager->persist($taikai);
        $this->entityManager->flush();

        return $taikai;
    }

    private function createDojo(string $shortname, string $name): Dojo
    {
        $dojo = new Dojo();
        $dojo->setShortname($shortname)->setName($name)->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);
        $this->entityManager->flush();

        return $dojo;
    }

    private function createKyudojin(string $firstname, string $lastname, string $club): Kyudojin
    {
        $kyudojin = new Kyudojin();
        $kyudojin->setLicenseId('L'.bin2hex(random_bytes(4)))
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setFederationClub($club)
            ->setFederationCountryCode('FR');

        $this->entityManager->persist($kyudojin);
        $this->entityManager->flush();

        return $kyudojin;
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

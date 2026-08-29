<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\LogEntry;
use App\Entity\User;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Piste d'audit (Gedmo Loggable), reprise du gem `audited` côté Rails.
 *
 * Rails ne l'expose nulle part dans son interface (voir MIGRATION.md) : ces
 * tests vérifient donc directement la table `ext_log_entries`, pas un écran.
 */
final class LoggableAuditTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->resetDatabase($this->entityManager);

        $this->admin = $this->createUser('admin@pikaichu.test');
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testCreatingADojoLogsACreateEntryWithTheAuthenticatedUsername(): void
    {
        $crawler = $this->client->request('GET', '/dojos/new');
        $this->client->submit($crawler->filter('form[name="dojo"]')->form([
            'dojo[shortname]' => 'nantes',
            'dojo[name]' => 'Kyudo Club Nantais',
            'dojo[city]' => 'Nantes',
            'dojo[countryCode]' => 'FR',
        ]));

        self::assertResponseRedirects('/dojos');

        $dojo = $this->entityManager->getRepository(Dojo::class)->findOneBy(['shortname' => 'nantes']);
        self::assertInstanceOf(Dojo::class, $dojo);

        $logs = $this->entityManager->getRepository(LogEntry::class)->findBy(['objectClass' => Dojo::class, 'objectId' => (string) $dojo->getId()]);
        self::assertCount(1, $logs);
        self::assertSame('create', $logs[0]->getAction());
        self::assertSame('admin@pikaichu.test', $logs[0]->getUsername());
        self::assertSame('Kyudo Club Nantais', $logs[0]->getData()['name'] ?? null);
    }

    public function testUpdatingADojoLogsAnUpdateEntryWithOnlyTheChangedFields(): void
    {
        $dojo = $this->createDojo('brest', 'Ancien nom');
        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/dojos/'.$dojo->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="dojo"]')->form([
            'dojo[shortname]' => 'brest',
            'dojo[name]' => 'Nouveau nom',
            // Ville inchangée par rapport à `createDojo()`, pour vérifier que
            // seuls les champs réellement modifiés sont journalisés.
            'dojo[city]' => 'Ville',
            'dojo[countryCode]' => 'FR',
        ]));

        self::assertResponseRedirects('/dojos');

        $logs = $this->entityManager->getRepository(LogEntry::class)->findBy(
            ['objectClass' => Dojo::class, 'objectId' => (string) $dojo->getId()],
            ['id' => 'ASC'],
        );
        self::assertCount(2, $logs, 'création + modification');
        self::assertSame('update', $logs[1]->getAction());
        self::assertSame('Nouveau nom', $logs[1]->getData()['name'] ?? null);
        // Rien n'a changé sur la ville : la valeur n'apparaît pas au titre de cette
        // modification, seuls les champs réellement modifiés sont journalisés.
        self::assertArrayNotHasKey('city', $logs[1]->getData());
    }

    /** Le mot de passe ne doit jamais atterrir dans la piste d'audit. */
    public function testUserPasswordIsNeverLogged(): void
    {
        $this->entityManager->clear();

        $logs = $this->entityManager->getRepository(LogEntry::class)->findBy(['objectClass' => User::class]);
        self::assertNotEmpty($logs, 'sanity check: creating the admin in setUp should have logged something');

        foreach ($logs as $log) {
            self::assertArrayNotHasKey('passwordDigest', $log->getData());
        }
    }

    private function createDojo(string $shortname, string $name): Dojo
    {
        $dojo = new Dojo();
        $dojo->setShortname($shortname)->setName($name)->setCity('Ville')->setCountryCode('FR');

        $this->entityManager->persist($dojo);
        $this->entityManager->flush();

        return $dojo;
    }

    private function createUser(string $email): User
    {
        $container = static::getContainer();
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Test')
            ->setLastname('Admin')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

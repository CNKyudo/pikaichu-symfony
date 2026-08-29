<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\User;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Tableau de bord, porté de `home_controller#index`. */
final class HomeControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testDashboardShowsManagedCounts(): void
    {
        $container = static::getContainer();
        $this->client->loginUser($this->createUser($container->get(UserPasswordHasherInterface::class)));

        $this->createDojo('zenko', 'Zenko Kyudojo');
        $this->createDojo('asahi', 'Asahi Kyudojo');

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2', $crawler->filter('.tile.is-child')->eq(1)->filter('p')->eq(1)->text());
        self::assertStringContainsString('0', $crawler->filter('.tile.is-child')->eq(0)->filter('p')->eq(1)->text());
    }

    private function createDojo(string $shortname, string $name): Dojo
    {
        $dojo = new Dojo();
        $dojo->setShortname($shortname)
            ->setName($name)
            ->setCity('Ville')
            ->setCountryCode('FR');

        $this->entityManager->persist($dojo);
        $this->entityManager->flush();

        return $dojo;
    }

    private function createUser(UserPasswordHasherInterface $hasher): User
    {
        $user = new User();
        $user->setEmailAddress('home@pikaichu.test')
            ->setFirstname('Home')
            ->setLastname('Tester')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

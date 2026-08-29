<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Modification de son propre compte, portée de `users_controller`.
 *
 * Rails ne rend cette action accessible par aucune vue ni aucun lien (voir
 * `UserAccountType`) : ce test couvre donc l'intention portée, pas un
 * comportement Rails observable.
 */
final class UserAccountControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDatabase($this->entityManager);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testCanUpdateOwnIdentity(): void
    {
        $user = $this->createUser('archer@pikaichu.test');
        $this->client->loginUser($user);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/account/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="user_account"]')->form([
            'user_account[firstname]' => 'Nouveau',
            'user_account[lastname]' => 'Nom',
            'user_account[emailAddress]' => 'archer@pikaichu.test',
            'user_account[locale]' => 'en',
        ]));

        self::assertResponseRedirects('/');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(User::class)->find($user->getId());
        self::assertSame('Nouveau', $reloaded?->getFirstname());
        self::assertSame('en', $reloaded->getLocale());
    }

    public function testRejectsEmailAlreadyUsedByAnotherAccount(): void
    {
        $this->createUser('other@pikaichu.test');
        $user = $this->createUser('archer@pikaichu.test');
        $this->client->loginUser($user);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/account/edit');

        $this->client->submit($crawler->filter('form[name="user_account"]')->form([
            'user_account[emailAddress]' => 'other@pikaichu.test',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/account/edit');

        self::assertResponseRedirects('/login');
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
}

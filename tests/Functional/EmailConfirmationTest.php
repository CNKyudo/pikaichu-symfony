<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Confirmation d'adresse courriel à l'inscription, absente côté Rails (voir
 * `EmailConfirmationService` et `MIGRATION.md`).
 */
final class EmailConfirmationTest extends WebTestCase
{
    use DatabaseResetTrait;
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->resetDatabase($this->entityManager);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testRegisteringSendsAConfirmationEmailAndDoesNotLogIn(): void
    {

        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('form')->form([
            'registration_form[emailAddress]' => 'archer@pikaichu.test',
            'registration_form[firstname]' => 'Test',
            'registration_form[lastname]' => 'Archer',
            'registration_form[plainPassword][first]' => 'Password1',
            'registration_form[plainPassword][second]' => 'Password1',
        ]));

        self::assertResponseRedirects('/login');

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'archer@pikaichu.test');

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailAddress' => 'archer@pikaichu.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isConfirmed());
        self::assertNotNull($user->getConfirmationToken());

        // Le compte étant inactif, l'inscription ne connecte pas automatiquement.
        $this->client->request('GET', '/taikais');
        self::assertResponseRedirects('/login');
    }

    public function testUnconfirmedUserCannotLogIn(): void
    {
        $this->createUser('archer@pikaichu.test', confirmed: false);

        $crawler = $this->client->request('GET', '/login');
        $token = $this->extractCsrfToken($crawler);

        $this->client->request('POST', '/login', [
            'email_address' => 'archer@pikaichu.test',
            'password' => 'Password1',
            '_csrf_token' => $token,
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('confirmer votre adresse email', $crawler->filter('body')->text());
    }

    public function testConfirmingWithAValidTokenAllowsLogin(): void
    {
        $user = $this->createUser('archer@pikaichu.test', confirmed: false);
        $user->setConfirmationToken('a-valid-token');

        $this->entityManager->flush();

        $this->client->request('GET', '/confirm-email/a-valid-token');

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('confirmée', $crawler->filter('body')->text());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded?->isConfirmed());
        self::assertNull($reloaded->getConfirmationToken());

        $token = $this->extractCsrfToken($this->client->request('GET', '/login'));
        $this->client->request('POST', '/login', [
            'email_address' => 'archer@pikaichu.test',
            'password' => 'Password1',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/taikais');
    }

    public function testConfirmingWithAnInvalidTokenShowsAnError(): void
    {

        $this->client->request('GET', '/confirm-email/not-a-real-token');

        self::assertResponseRedirects('/confirm-email/resend');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('invalide', $crawler->filter('body')->text());
    }

    /** Ne doit jamais révéler si l'adresse existe ou est déjà confirmée : même écran de confirmation. */
    public function testResendingAlwaysShowsTheSameGenericConfirmation(): void
    {
        $this->createUser('archer@pikaichu.test', confirmed: false);

        $crawler = $this->client->request('GET', '/confirm-email/resend');
        $this->client->submit($crawler->filter('form')->form([
            'resend_confirmation_form[emailAddress]' => 'unknown@pikaichu.test',
        ]));

        self::assertResponseRedirects('/login');
        self::assertEmailCount(0);
    }

    private function extractCsrfToken(Crawler $crawler): string
    {
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    private function createUser(string $email, bool $confirmed): User
    {
        $container = static::getContainer();
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Test')
            ->setLastname('Archer');
        $user->setPassword($passwordHasher->hashPassword($user, 'Password1'));
        if ($confirmed) {
            $user->setConfirmedAt(new \DateTimeImmutable());
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

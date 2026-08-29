<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Réinitialisation de mot de passe, portée de `passwords_controller`.
 */
final class PasswordResetControllerTest extends WebTestCase
{
    use DatabaseResetTrait;
    use MailerAssertionsTrait;

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

    public function testRequestingAResetSendsAnEmailAndShowsGenericConfirmation(): void
    {
        $this->createUser('archer@pikaichu.test', 'OldPassword1');

        $crawler = $this->client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form([
            'reset_password_request_form[emailAddress]' => 'archer@pikaichu.test',
        ]));

        self::assertResponseRedirects('/reset-password/check-email');

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'archer@pikaichu.test');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    /** Ne doit jamais révéler si l'adresse existe ou non : même écran de confirmation. */
    public function testRequestingAResetForUnknownEmailShowsTheSameConfirmation(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');

        $this->client->submit($crawler->filter('form')->form([
            'reset_password_request_form[emailAddress]' => 'unknown@pikaichu.test',
        ]));

        self::assertResponseRedirects('/reset-password/check-email');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        self::assertEmailCount(0);
    }

    public function testFullResetFlowChangesThePassword(): void
    {
        $user = $this->createUser('archer@pikaichu.test', 'OldPassword1');

        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->filter('form')->form([
            'reset_password_request_form[emailAddress]' => 'archer@pikaichu.test',
        ]));

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $body = (string) $email->getHtmlBody();
        if (1 !== preg_match('#/reset-password/reset/([^"\s<]+)#', $body, $matches)) {
            self::fail('Reset link not found in email.');
        }

        $token = $matches[1];

        // Suit le lien reçu par courriel : le jeton est déplacé en session puis retiré de l'URL.
        $this->client->request('GET', '/reset-password/reset/'.$token);
        self::assertResponseRedirects('/reset-password/reset');

        $crawler = $this->client->request('GET', '/reset-password/reset');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form([
            'change_password_form[plainPassword][first]' => 'NewPassword2',
            'change_password_form[plainPassword][second]' => 'NewPassword2',
        ]));

        self::assertResponseRedirects('/login');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertTrue($this->passwordHasher->isPasswordValid($reloaded, 'NewPassword2'));
    }

    public function testAnInvalidTokenRedirectsToTheRequestFormWithAnError(): void
    {
        $this->client->request('GET', '/reset-password/reset/not-a-real-token');
        $this->client->request('GET', '/reset-password/reset');

        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.notification.is-danger', 'invalide');
    }

    private function createUser(string $email, string $password): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Test')
            ->setLastname('User')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

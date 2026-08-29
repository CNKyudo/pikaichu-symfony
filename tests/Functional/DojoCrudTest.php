<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Entity\User;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CRUD du référentiel des clubs, porté de `dojos_controller` côté Rails.
 *
 * Rails n'expose que index/new/create/edit/update/destroy : il n'y a pas d'écran
 * de détail, la liste renvoyant directement vers le formulaire de modification.
 *
 * Les formulaires sont ciblés par leur structure (`form[name=dojo]`, URL d'action)
 * et non par le libellé des boutons, qui dépend de la locale.
 */
final class DojoCrudTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->client->loginUser($this->createUser($container->get(UserPasswordHasherInterface::class)));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testIndexListsDojosOrderedByShortname(): void
    {
        $this->createDojo('zenko', 'Zenko Kyudojo');
        $this->createDojo('asahi', 'Asahi Kyudojo');

        $crawler = $this->client->request('GET', '/dojos');

        self::assertResponseIsSuccessful();

        $shortnames = $crawler->filter('table tbody tr td:first-child')->each(
            static fn ($node): string => trim($node->text()),
        );

        self::assertSame(['asahi', 'zenko'], $shortnames);
    }

    public function testCanCreateDojo(): void
    {
        $crawler = $this->client->request('GET', '/dojos/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="dojo"]')->form([
            'dojo[shortname]' => 'nantes',
            'dojo[name]' => 'Kyudo Club Nantais',
            'dojo[city]' => 'Nantes',
            'dojo[countryCode]' => 'FR',
        ]));

        self::assertResponseRedirects('/dojos');

        $dojo = $this->entityManager->getRepository(Dojo::class)->findOneBy(['shortname' => 'nantes']);
        self::assertInstanceOf(Dojo::class, $dojo);
        self::assertSame('Kyudo Club Nantais', $dojo->getName());
        self::assertSame('FR', $dojo->getCountryCode());
    }

    /**
     * Rails normalise avant enregistrement : `shortname` en minuscules, `name`
     * détouré, `country_code` en majuscules.
     */
    public function testNormalisesFieldsOnCreate(): void
    {
        $crawler = $this->client->request('GET', '/dojos/new');

        $this->client->submit($crawler->filter('form[name="dojo"]')->form([
            'dojo[shortname]' => '  ReNNeS  ',
            'dojo[name]' => '  Cercle de Kyudo  ',
            'dojo[city]' => 'Rennes',
            'dojo[countryCode]' => 'FR',
        ]));

        self::assertResponseRedirects('/dojos');

        $dojo = $this->entityManager->getRepository(Dojo::class)->findOneBy(['shortname' => 'rennes']);
        self::assertInstanceOf(Dojo::class, $dojo);
        self::assertSame('Cercle de Kyudo', $dojo->getName());
    }

    /** `shortname` fait au moins 3 caractères côté Rails. */
    public function testRejectsTooShortShortname(): void
    {
        $crawler = $this->client->request('GET', '/dojos/new');

        $this->client->submit($crawler->filter('form[name="dojo"]')->form([
            'dojo[shortname]' => 'ab',
            'dojo[name]' => 'Trop court',
            'dojo[city]' => 'Nantes',
            'dojo[countryCode]' => 'FR',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager->getRepository(Dojo::class)->findOneBy(['shortname' => 'ab']));
    }

    public function testCanEditDojo(): void
    {
        $dojo = $this->createDojo('brest', 'Ancien nom');

        $crawler = $this->client->request('GET', '/dojos/'.$dojo->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="dojo"]')->form([
            'dojo[shortname]' => 'brest',
            'dojo[name]' => 'Nouveau nom',
            'dojo[city]' => 'Brest',
            'dojo[countryCode]' => 'FR',
        ]));

        self::assertResponseRedirects('/dojos');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Dojo::class)->find($dojo->getId());
        self::assertInstanceOf(Dojo::class, $reloaded);
        self::assertSame('Nouveau nom', $reloaded->getName());
    }

    public function testCanDeleteUnusedDojo(): void
    {
        $dojo = $this->createDojo('lorient', 'Kyudo Lorient');
        $id = $dojo->getId();

        $crawler = $this->client->request('GET', '/dojos');
        $this->client->submit($crawler->filter('form[action="/dojos/'.$id.'"]')->form());

        self::assertResponseRedirects('/dojos');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Dojo::class)->find($id));
    }

    /**
     * Rails déclare `has_many :participating_dojos, dependent: :restrict_with_error` :
     * un club engagé dans un taikai ne peut pas être supprimé.
     */
    public function testCannotDeleteDojoUsedByATaikai(): void
    {
        $dojo = $this->createDojo('vannes', 'Kyudo Vannes');
        $this->engageDojoInTaikai($dojo);
        $id = $dojo->getId();

        $crawler = $this->client->request('GET', '/dojos');
        $this->client->submit($crawler->filter('form[action="/dojos/'.$id.'"]')->form());

        self::assertResponseRedirects('/dojos');

        $this->client->followRedirect();
        self::assertSelectorExists('.notification.is-danger');

        $this->entityManager->clear();
        self::assertInstanceOf(Dojo::class, $this->entityManager->getRepository(Dojo::class)->find($id));
    }

    public function testRequiresAuthentication(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/dojos');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
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

    /** Engage le club dans un taikai, ce qui doit bloquer sa suppression. */
    private function engageDojoInTaikai(Dojo $dojo): void
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

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName((string) $dojo->getShortname());
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $this->entityManager->flush();
    }

    private function createUser(UserPasswordHasherInterface $hasher): User
    {
        $user = new User();
        $user->setEmailAddress('dojo-crud@pikaichu.test')
            ->setFirstname('Dojo')
            ->setLastname('Tester')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

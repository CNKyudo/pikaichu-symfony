<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Kyudojin;
use App\Entity\Participant;
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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Saisie manuelle des participants, portée de `participants_controller`.
 *
 * L'import Excel relève du même contrôleur côté Rails mais reste à porter ; il
 * n'est donc pas couvert ici.
 */
final class ParticipantCrudTest extends WebTestCase
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

    public function testParticipantsAreListedOnHostClubPage(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->hostClubPath($taikai, $participatingDojo));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tanaka', $crawler->filter('table')->text());
    }

    public function testCanCreateParticipantManually(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->participantsPath($taikai, $participatingDojo).'/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="participant"]')->form([
            'participant[firstname]' => 'Yumi',
            'participant[lastname]' => 'Tanaka',
            'participant[club]' => 'nantes',
        ]));

        self::assertResponseRedirects($this->participantsPath($taikai, $participatingDojo).'/new');

        $participant = $this->findParticipant();
        self::assertSame('Yumi', $participant->getFirstname());
        self::assertSame('Tanaka', $participant->getLastname());
        self::assertSame('nantes', $participant->getClub());
    }

    /** Nom et prénom sont obligatoires côté Rails. */
    public function testRejectsParticipantWithoutName(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->participantsPath($taikai, $participatingDojo).'/new');

        $this->client->submit($crawler->filter('form[name="participant"]')->form([
            'participant[firstname]' => '',
            'participant[lastname]' => '',
            'participant[club]' => 'nantes',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(Participant::class)->findAll());
    }

    /**
     * Rails recopie l'identité du licencié sélectionné : le prénom, le nom et le
     * club saisis à la main sont écrasés.
     */
    public function testSelectingAKyudojinOverridesTypedIdentity(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $kyudojin = $this->createKyudojin('Haruki', 'Sato', 'ktlg');

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->participantsPath($taikai, $participatingDojo).'/new');

        $this->submitParticipantForm($crawler, [
            'participant[kyudojin]' => (string) $kyudojin->getId(),
            'participant[firstname]' => 'Saisie',
            'participant[lastname]' => 'Ignoree',
            'participant[club]' => 'ignore',
        ]);

        self::assertResponseRedirects($this->participantsPath($taikai, $participatingDojo).'/new');

        $participant = $this->findParticipant();
        self::assertSame('Haruki', $participant->getFirstname());
        self::assertSame('Sato', $participant->getLastname());
        self::assertSame('ktlg', $participant->getClub());
    }

    /** Un même licencié ne peut pas être inscrit deux fois dans le même club hôte. */
    public function testRejectsDuplicateKyudojinInSameHostClub(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $kyudojin = $this->createKyudojin('Haruki', 'Sato', 'ktlg');

        $participant = $this->addParticipant($participatingDojo, 'Haruki', 'Sato');
        $participant->setKyudojin($kyudojin);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->participantsPath($taikai, $participatingDojo).'/new');

        $this->submitParticipantForm($crawler, [
            'participant[kyudojin]' => (string) $kyudojin->getId(),
            'participant[firstname]' => 'Haruki',
            'participant[lastname]' => 'Sato',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager->getRepository(Participant::class)->findAll());
    }

    public function testCanEditParticipant(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $participant = $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka');
        $id = $participant->getId();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request(
            'GET',
            $this->participantsPath($taikai, $participatingDojo).'/'.$id.'/edit',
        );
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="participant"]')->form([
            'participant[firstname]' => 'Yumiko',
            'participant[lastname]' => 'Tanaka',
        ]));

        self::assertResponseRedirects($this->hostClubPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Participant::class)->find($id);
        self::assertInstanceOf(Participant::class, $reloaded);
        self::assertSame('Yumiko', $reloaded->getFirstname());
    }

    public function testCanDeleteParticipant(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $participant = $this->addParticipant($participatingDojo, 'Yumi', 'Tanaka');
        $id = $participant->getId();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->hostClubPath($taikai, $participatingDojo));
        $action = $this->participantsPath($taikai, $participatingDojo).'/'.$id;

        $this->client->submit($crawler->filter('form[action="'.$action.'"]')->form());

        self::assertResponseRedirects($this->hostClubPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Participant::class)->find($id));
    }

    /** Sans droit sur le taikai, la saisie est refusée. */
    public function testPlainUserCannotCreateParticipant(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));

        $this->client->request('GET', $this->participantsPath($taikai, $participatingDojo).'/new');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Soumet le formulaire de participant, en passant outre la validation de
     * `participant[kyudojin]` : ce champ est désormais une recherche AJAX
     * (TomSelect) dont le crawler ne connaît pas les <option> chargées côté
     * client.
     *
     * @param array<string, string> $fields
     */
    private function submitParticipantForm(Crawler $crawler, array $fields): void
    {
        $form = $crawler->filter('form[name="participant"]')->form();

        if (\array_key_exists('participant[kyudojin]', $fields)) {
            $kyudojinField = $form->get('participant[kyudojin]');
            self::assertInstanceOf(ChoiceFormField::class, $kyudojinField);
            $kyudojinField->disableValidation()->setValue($fields['participant[kyudojin]']);
            unset($fields['participant[kyudojin]']);
        }

        $form->setValues($fields);

        $this->client->submit($form);
    }

    private function participantsPath(Taikai $taikai, ParticipatingDojo $participatingDojo): string
    {
        return \sprintf(
            '/taikais/%d/participating-dojos/%d/participants',
            $taikai->getId(),
            $participatingDojo->getId(),
        );
    }

    private function hostClubPath(Taikai $taikai, ParticipatingDojo $participatingDojo): string
    {
        return \sprintf(
            '/taikais/%d/participating-dojos/%d/edit',
            $taikai->getId(),
            $participatingDojo->getId(),
        );
    }

    private function findParticipant(): Participant
    {
        $this->entityManager->clear();
        $participants = $this->entityManager->getRepository(Participant::class)->findAll();
        self::assertCount(1, $participants);

        return $participants[0];
    }

    /** @return array{Taikai, ParticipatingDojo} */
    private function createContext(): array
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

        $dojo = new Dojo();
        $dojo->setShortname('nantes')->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

        $this->entityManager->flush();

        return [$taikai, $participatingDojo];
    }

    private function addParticipant(ParticipatingDojo $participatingDojo, string $firstname, string $lastname): Participant
    {
        $participant = new Participant();
        $participant->setFirstname($firstname)
            ->setLastname($lastname)
            ->setClub('nantes');
        $participatingDojo->addParticipant($participant);

        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant;
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

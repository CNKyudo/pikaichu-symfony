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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Import Excel des participants, porté de `participants#import`.
 *
 * Le classeur attendu est l'export « Kyudo - Interface de gestion », dont la
 * première ligne est une bannière à ignorer avant l'en-tête.
 */
final class ParticipantImportTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->loadStaffRoles();

        $this->admin = $this->createUser(
            'admin@pikaichu.test',
            $container->get(UserPasswordHasherInterface::class),
        );
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
        $this->entityManager->close();
    }

    public function testImportsEveryRowOfTheSpreadsheet(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->commitSeeding($this->entityManager);

        $this->submitImport($taikai, $participatingDojo, [
            ['Prénom' => 'Yumi', 'Nom' => 'Tanaka', 'Club' => 'ktlg'],
            ['Prénom' => 'Ren', 'Nom' => 'Ito', 'Club' => 'ktlg'],
        ]);

        self::assertResponseRedirects($this->hostClubPath($taikai, $participatingDojo));

        $this->entityManager->clear();
        $participants = $this->entityManager->getRepository(Participant::class)->findAll();
        self::assertCount(2, $participants);

        $names = array_map(static fn (Participant $p): string => $p->getDisplayName(), $participants);
        sort($names);
        self::assertSame(['Ren Ito', 'Yumi Tanaka'], $names);
    }

    /** La bannière de l'export Kyudo Gestion ne doit pas produire de participant. */
    public function testSkipsTheKyudoGestionBanner(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->commitSeeding($this->entityManager);

        $this->submitImport(
            $taikai,
            $participatingDojo,
            [['Prénom' => 'Yumi', 'Nom' => 'Tanaka', 'Club' => 'ktlg']],
            banner: true,
        );

        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(Participant::class)->findAll());
    }

    /**
     * Le rapprochement avec la base des licenciés ignore la casse, les accents et
     * les traits d'union, les deux sources ne les écrivant pas de la même façon.
     */
    public function testLinksKyudojinIgnoringCaseAccentsAndHyphens(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $kyudojin = $this->createKyudojin('Jean-Éric', 'Lefèvre', 'ktlg');
        $this->commitSeeding($this->entityManager);

        $this->submitImport($taikai, $participatingDojo, [
            ['Prénom' => 'JEAN ERIC', 'Nom' => 'LEFEVRE', 'Club' => 'ktlg'],
        ]);

        $this->entityManager->clear();
        $participants = $this->entityManager->getRepository(Participant::class)->findAll();
        self::assertCount(1, $participants);

        $linked = $participants[0]->getKyudojin();
        self::assertInstanceOf(Kyudojin::class, $linked);
        self::assertSame($kyudojin->getId(), $linked->getId());

        // Le fichier fait foi pour l'identité affichée, comme côté Rails.
        self::assertSame('JEAN ERIC', $participants[0]->getFirstname());
    }

    /** Un archer inconnu de la base fédérale est importé, mais signalé. */
    public function testReportsRowsWithoutMatchingKyudojin(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->commitSeeding($this->entityManager);

        $this->submitImport($taikai, $participatingDojo, [
            ['Prénom' => 'Yumi', 'Nom' => 'Tanaka', 'Club' => 'ktlg'],
        ]);

        $crawler = $this->client->followRedirect();

        // Ciblé sur la notification : le tableau des participants contient lui
        // aussi ces noms, mais dans des cellules distinctes.
        $warning = $crawler->filter('.notification.is-warning');
        self::assertGreaterThan(0, $warning->count());
        self::assertStringContainsString('Yumi Tanaka', $warning->text());
        self::assertStringNotContainsString('participant.import', $warning->text());

        $this->entityManager->clear();
        $participants = $this->entityManager->getRepository(Participant::class)->findAll();
        self::assertCount(1, $participants);
        self::assertNull($participants[0]->getKyudojin());
    }

    public function testWithoutFileNothingIsImported(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->commitSeeding($this->entityManager);

        $this->client->request('POST', $this->importPath($taikai, $participatingDojo), [
            '_token' => $this->importToken($taikai, $participatingDojo),
        ]);

        self::assertResponseRedirects($this->hostClubPath($taikai, $participatingDojo));
        $this->client->followRedirect();
        self::assertSelectorExists('.notification.is-danger');

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(Participant::class)->findAll());
    }

    public function testPlainUserCannotImport(): void
    {
        [$taikai, $participatingDojo] = $this->createContext();
        $this->commitSeeding($this->entityManager);

        $this->client->loginUser($this->createUser(
            'outsider@pikaichu.test',
            static::getContainer()->get(UserPasswordHasherInterface::class),
        ));

        $this->client->request('POST', $this->importPath($taikai, $participatingDojo));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function submitImport(
        Taikai $taikai,
        ParticipatingDojo $participatingDojo,
        array $rows,
        bool $banner = false,
    ): void {
        $this->client->request(
            'POST',
            $this->importPath($taikai, $participatingDojo),
            ['_token' => $this->importToken($taikai, $participatingDojo)],
            ['excel' => $this->createSpreadsheet($rows, $banner)],
        );
    }

    private function importToken(Taikai $taikai, ParticipatingDojo $participatingDojo): string
    {
        $crawler = $this->client->request('GET', $this->hostClubPath($taikai, $participatingDojo));
        $token = $crawler->filter('form[action="'.$this->importPath($taikai, $participatingDojo).'"] input[name="_token"]');

        self::assertGreaterThan(0, $token->count(), "Le formulaire d'import est absent de la page.");

        return (string) $token->attr('value');
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function createSpreadsheet(array $rows, bool $banner): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $line = 1;
        if ($banner) {
            $sheet->setCellValue('A'.$line, 'Kyudo - Interface de gestion');
            ++$line;
        }

        foreach (['Prénom', 'Nom', 'Club'] as $column => $header) {
            $sheet->setCellValue([$column + 1, $line], $header);
        }

        ++$line;

        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$line, $row['Prénom']);
            $sheet->setCellValue('B'.$line, $row['Nom']);
            $sheet->setCellValue('C'.$line, $row['Club']);
            ++$line;
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, 'participants.xlsx', null, null, true);
    }

    private function importPath(Taikai $taikai, ParticipatingDojo $participatingDojo): string
    {
        return \sprintf(
            '/taikais/%d/participating-dojos/%d/participants/import',
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
        $dojo->setShortname('ktlg')->setName('Club test')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('ktlg');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

        $this->entityManager->flush();

        return [$taikai, $participatingDojo];
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

    private function createUser(string $email, UserPasswordHasherInterface $hasher): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Test')
            ->setLastname('User')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

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

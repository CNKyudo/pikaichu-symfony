<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Service\TaikaiStateMachine;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Export Excel d'un taikai, porté de `taikais#export`.
 */
final class TaikaiExportControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UserPasswordHasherInterface $passwordHasher;

    private TaikaiStateMachine $stateMachine;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->stateMachine = $container->get(TaikaiStateMachine::class);

        $this->resetDatabase($this->entityManager);
        $this->loadStaffRoles();

        $this->admin = $this->createUser('admin@pikaichu.test');
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testExportForIndividualTaikaiHasExpectedSheetsAndData(): void
    {
        $taikai = $this->createIndividualContext();
        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $spreadsheet = $this->loadSpreadsheetFromResponse();
        $sheetNames = $spreadsheet->getSheetNames();

        self::assertContains('Résumé', $sheetNames);
        self::assertContains('Staff', $sheetNames);
        self::assertContains('Participants', $sheetNames);
        self::assertContains('Résultats individuels', $sheetNames);
        self::assertNotContains('Résultats en équipes', $sheetNames);
        // Aucune transition n'a eu lieu : pas d'évènement, donc pas de journal.
        self::assertNotContains('Journal', $sheetNames);

        $summary = $spreadsheet->getSheetByName('Résumé');
        self::assertNotNull($summary);
        self::assertSame($taikai->getShortname(), $summary->getCell('B2')->getValue());

        $results = $spreadsheet->getSheetByName('Résultats individuels');
        self::assertNotNull($results);
        $text = implode(' ', $this->allCellValues($results));
        self::assertStringContainsString('Yumi', $text);
        self::assertStringContainsString('Tanaka', $text);
    }

    public function testExportForTeamTaikaiHasTeamResultsSheetOnly(): void
    {
        $taikai = $this->createTeamContext();
        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/export');

        self::assertResponseIsSuccessful();
        $spreadsheet = $this->loadSpreadsheetFromResponse();
        $sheetNames = $spreadsheet->getSheetNames();

        self::assertContains('Résultats en équipes', $sheetNames);
        self::assertNotContains('Résultats individuels', $sheetNames);

        $results = $spreadsheet->getSheetByName('Résultats en équipes');
        self::assertNotNull($results);
        $text = implode(' ', $this->allCellValues($results));
        self::assertStringContainsString('Équipe A', $text);
    }

    public function testExportIncludesJournalSheetOnceATransitionHappened(): void
    {
        $taikai = $this->createIndividualContext();
        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->admin);
        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/export');

        self::assertResponseIsSuccessful();
        $spreadsheet = $this->loadSpreadsheetFromResponse();

        self::assertContains('Journal', $spreadsheet->getSheetNames());
    }

    public function testExportRequiresAuthentication(): void
    {
        $taikai = $this->createIndividualContext();
        $this->commitSeeding($this->entityManager);

        static::ensureKernelShutdown();
        $anonymousClient = static::createClient();
        $anonymousClient->request('GET', '/taikais/'.$taikai->getId().'/export');

        self::assertResponseRedirects('/login');
    }

    private function loadSpreadsheetFromResponse(): Spreadsheet
    {
        $content = $this->client->getInternalResponse()->getContent();
        $tmpFile = tempnam(sys_get_temp_dir(), 'pikaichu-export-test-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, $content);

        try {
            return IOFactory::load($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    /** @return list<string> */
    private function allCellValues(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $values = [];
        foreach ($sheet->toArray() as $row) {
            foreach ($row as $cell) {
                if (null !== $cell) {
                    $values[] = (string) $cell;
                }
            }
        }

        return $values;
    }

    private function createIndividualContext(): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(4)
            ->setNumTargets(6)
            ->setTachiSize(1)
            ->setDistributed(false);
        $this->entityManager->persist($taikai);

        $dojo = new Dojo();
        $dojo->setShortname('nantes')->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $participant = new Participant();
        $participant->setFirstname('Yumi')->setLastname('Tanaka')->setClub('nantes');
        $participatingDojo->addParticipant($participant);
        $this->entityManager->persist($participant);

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

        $this->entityManager->flush();

        return $taikai;
    }

    private function createTeamContext(): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Team)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(4)
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

        $team = new Team();
        $team->setShortname('Équipe A');

        $participatingDojo->addTeam($team);
        $this->entityManager->persist($team);

        $participant = new Participant();
        $participant->setFirstname('Yumi')->setLastname('Tanaka')->setClub('nantes');
        $participatingDojo->addParticipant($participant);
        $team->addParticipant($participant);
        $this->entityManager->persist($participant);

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

        $this->entityManager->flush();

        return $taikai;
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

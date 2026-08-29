<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Result;
use App\Entity\Score;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\TaikaiTransition;
use App\Entity\User;
use App\Enum\ResultStatus;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Classement annuel du championnat, porté de `championship_controller`.
 */
final class ChampionshipControllerTest extends WebTestCase
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

    public function testIndexListsAvailableYears(): void
    {
        $this->createDoneKintekiTaikai(2026, 'A', 'Yumi', 'Tanaka', 4);
        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/championship');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2026', $crawler->filter('body')->text());
    }

    public function testExportRanksParticipantsWithAtLeastThreeParticipationsOnTheirBestThree(): void
    {
        // Yumi Tanaka concourt 3 fois dans l'année : elle doit être classée sur
        // la somme de ses 3 scores (aucun n'est écarté puisqu'il n'y en a que 3).
        $this->createDoneKintekiTaikai(2026, 'A', 'Yumi', 'Tanaka', 2);
        $this->createDoneKintekiTaikai(2026, 'A', 'Yumi', 'Tanaka', 4);
        $this->createDoneKintekiTaikai(2026, 'A', 'Yumi', 'Tanaka', 6);
        // Solo Archer ne concourt qu'une fois : jamais classé (minimum 3 participations).
        $this->createDoneKintekiTaikai(2026, 'A', 'Solo', 'Archer', 10);
        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', '/championship/2026/export');

        self::assertResponseIsSuccessful();
        $spreadsheet = $this->loadSpreadsheetFromResponse();

        self::assertSame(
            ['Kinteki Classement Indiv', 'Enteki Classement Indiv', 'Kinteki Résultats', 'Enteki Résultats', 'DEBUG - Taikais'],
            $spreadsheet->getSheetNames(),
        );

        $ranking = $spreadsheet->getSheetByName('Kinteki Classement Indiv');
        self::assertNotNull($ranking);
        $rankingText = implode(' ', $this->allCellValues($ranking));
        self::assertStringContainsString('TANAKA Yumi', $rankingText);
        self::assertStringNotContainsString('ARCHER Solo', $rankingText);
        self::assertEquals(12, $ranking->getCell('D2')->getValue(), 'total = 2 + 4 + 6');

        $results = $spreadsheet->getSheetByName('Kinteki Résultats');
        self::assertNotNull($results);
        $resultsText = implode(' ', $this->allCellValues($results));
        // Toutes les participations apparaissent dans l'onglet « Résultats », classées ou non.
        self::assertStringContainsString('TANAKA Yumi', $resultsText);
        self::assertStringContainsString('ARCHER Solo', $resultsText);

        $debug = $spreadsheet->getSheetByName('DEBUG - Taikais');
        self::assertNotNull($debug);
        self::assertSame(5, $debug->getHighestRow(), '1 en-tête + 4 taikai');
    }

    public function testExportRequiresAuthentication(): void
    {
        $this->createDoneKintekiTaikai(2026, 'A', 'Yumi', 'Tanaka', 4);
        $this->commitSeeding($this->entityManager);

        static::ensureKernelShutdown();
        $anonymousClient = static::createClient();
        $anonymousClient->request('GET', '/championship/2026/export');

        self::assertResponseRedirects('/login');
    }

    private function loadSpreadsheetFromResponse(): Spreadsheet
    {
        $content = $this->client->getInternalResponse()->getContent();
        $tmpFile = tempnam(sys_get_temp_dir(), 'pikaichu-championship-test-');
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

    private function createDoneKintekiTaikai(int $year, string $category, string $firstname, string $lastname, int $hits): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de '.$firstname.' '.bin2hex(random_bytes(2)))
            ->setStartDate(new \DateTimeImmutable($year.'-06-01'))
            ->setEndDate(new \DateTimeImmutable($year.'-06-01'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setCategory($category)
            ->setTotalNumArrows(4)
            ->setNumTargets(6)
            ->setTachiSize(1)
            ->setDistributed(false);
        $this->entityManager->persist($taikai);

        $dojo = new Dojo();
        $dojo->setShortname('nantes-'.bin2hex(random_bytes(4)))->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $participant = new Participant();
        $participant->setFirstname($firstname)->setLastname($lastname)->setClub('nantes')->setRank(1);
        $participatingDojo->addParticipant($participant);
        $this->entityManager->persist($participant);

        $score = new Score();
        $score->setParticipant($participant);

        $participant->addScore($score);
        $this->entityManager->persist($score);
        $this->setHits($score, $hits);

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

        $transition = new TaikaiTransition();
        $transition->setToState(TaikaiState::Done)->setSortKey(1)->setMostRecent(true);
        $taikai->addTransition($transition);
        $this->entityManager->persist($transition);

        $this->entityManager->flush();

        return $taikai;
    }

    private function setHits(Score $score, int $hits): void
    {
        for ($i = 0; $i < $hits; ++$i) {
            $result = new Result();
            $result->setScore($score)->setRound(1)->setIndex($i + 1)->setStatus(ResultStatus::Hit)->setFinal(true);
            $score->addResult($result);
            $this->entityManager->persist($result);
        }

        $score->recalculateFromResults();
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

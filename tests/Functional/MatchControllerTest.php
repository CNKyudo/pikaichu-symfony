<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ResultStatus;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Service\MatchService;
use App\Service\ScoreInitializer;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tableau final d'un tournoi à matchs, porté de `matches_controller`.
 */
final class MatchControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UserPasswordHasherInterface $passwordHasher;

    private MatchService $matchService;

    private ScoreInitializer $scoreInitializer;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->matchService = $container->get(MatchService::class);
        $this->scoreInitializer = $container->get(ScoreInitializer::class);

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

    public function testIndexShowsTheBracket(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 4);
        $this->matchService->createBracket($taikai, $teams);

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/matches');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('T1', $text);
        self::assertStringContainsString('T4', $text);
    }

    public function testCanReassignTeamsAndWinnerThroughEditForm(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 4);
        $this->matchService->createBracket($taikai, $teams);

        $this->commitSeeding($this->entityManager);

        $taikai = $this->entityManager->getRepository(Taikai::class)->find($taikai->getId());
        self::assertInstanceOf(Taikai::class, $taikai);
        $semiFinal1 = $this->findMatch($taikai, TaikaiMatch::LEVEL_SEMI_FINAL, 1);

        $crawler = $this->client->request('GET', '/taikais/'.$taikai->getId().'/matches/'.$semiFinal1->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="match"]')->form([
            'team1_id' => (string) $semiFinal1->getTeam1()?->getId(),
            'team2_id' => (string) $semiFinal1->getTeam2()?->getId(),
            'winner' => '1',
        ]));

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/matches');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(TaikaiMatch::class)->find($semiFinal1->getId());
        self::assertSame(1, $reloaded?->getWinner());
    }

    public function testSelectWinnerPicksHighestScoringTeam(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 4);
        $this->matchService->createBracket($taikai, $teams);
        $this->scoreInitializer->initialize($taikai);
        $this->commitSeeding($this->entityManager);

        $taikai = $this->entityManager->getRepository(Taikai::class)->find($taikai->getId());
        self::assertInstanceOf(Taikai::class, $taikai);
        $semiFinal1 = $this->findMatch($taikai, TaikaiMatch::LEVEL_SEMI_FINAL, 1);

        // Valide toutes les flèches d'une équipe pour rendre le match décidable.
        foreach ([1, 2] as $slot) {
            $team = $semiFinal1->getTeam($slot);
            self::assertInstanceOf(Team::class, $team);
            foreach ($team->getParticipants() as $participant) {
                $score = $participant->getScore($semiFinal1);
                self::assertNotNull($score);
                foreach ($score->getResults() as $result) {
                    $result->setStatus(1 === $slot ? ResultStatus::Hit : ResultStatus::Miss);
                    $result->setFinal(true);
                }

                $score->recalculateFromResults();
            }

            $team->getScore($semiFinal1)?->recalculateFromTeam();
        }

        $this->entityManager->flush();

        self::assertTrue($semiFinal1->isFinalized(), 'sanity check: match should be finalized');
        self::assertTrue($semiFinal1->isDecidable(), 'sanity check: match should be decidable');

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/matches');
        $token = $this->extractSelectWinnerToken($semiFinal1);

        $this->client->request('POST', '/taikais/'.$taikai->getId().'/matches/'.$semiFinal1->getId().'/select-winner', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/taikais/'.$taikai->getId().'/matches');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(TaikaiMatch::class)->find($semiFinal1->getId());
        self::assertSame(1, $reloaded?->getWinner());
    }

    /** Sans droit sur le taikai, l'écran est refusé. */
    public function testPlainUserCannotAccess(): void
    {
        $taikai = $this->createTaikai();
        $teams = $this->createTeams($taikai, 4);
        $this->matchService->createBracket($taikai, $teams);

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));

        $this->client->request('GET', '/taikais/'.$taikai->getId().'/matches');

        self::assertResponseStatusCodeSame(403);
    }

    private function extractSelectWinnerToken(TaikaiMatch $match): string
    {
        $content = (string) $this->client->getResponse()->getContent();
        $pattern = '#action="[^"]*/matches/'.$match->getId().'/select-winner"[^>]*>\s*'
            .'<input type="hidden" name="_token" value="([^"]+)"#s';
        if (1 !== preg_match($pattern, $content, $matches)) {
            self::fail('Select-winner CSRF token not found in response.');
        }

        return $matches[1];
    }

    private function findMatch(Taikai $taikai, int $level, int $index): TaikaiMatch
    {
        foreach ($taikai->getMatchesAtLevel($level) as $match) {
            if ($match->getIndex() === $index) {
                return $match;
            }
        }

        self::fail(\sprintf('No match found at level %d index %d', $level, $index));
    }

    private function createTaikai(): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Matches)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(4)
            ->setNumTargets(6)
            ->setTachiSize(1)
            ->setDistributed(false);
        $this->entityManager->persist($taikai);

        $staff = new Staff();
        $staff->setRole($this->findRole(StaffRoleCode::TaikaiAdmin))->setUser($this->admin);
        $taikai->addStaff($staff);
        $this->entityManager->persist($staff);

        $this->entityManager->flush();

        return $taikai;
    }

    /** @return list<Team> */
    private function createTeams(Taikai $taikai, int $count): array
    {
        $dojo = new Dojo();
        $dojo->setShortname('nantes')->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $teams = [];
        for ($i = 1; $i <= $count; ++$i) {
            $team = new Team();
            $team->setShortname('T'.$i);
            $participatingDojo->addTeam($team);
            $this->entityManager->persist($team);

            $participant = new Participant();
            $participant->setFirstname('Archer'.$i)->setLastname('T'.$i)->setClub('nantes');
            $participatingDojo->addParticipant($participant);
            $team->addParticipant($participant);
            $this->entityManager->persist($participant);

            $teams[] = $team;
        }

        $this->entityManager->flush();

        return $teams;
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

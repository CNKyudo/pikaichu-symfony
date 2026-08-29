<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\TaikaiEvent;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ResultStatus;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Service\DrawService;
use App\Service\MarkingService;
use App\Service\TaikaiStateMachine;
use App\Service\TieBreakService;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Ajustement manuel des rangs, porté de `tie_break_controller`.
 *
 * L'écran suppose un taikai déjà en tie-break avec un vrai ex æquo : le
 * scénario (un archer touche tout, deux autres manquent tout) reprend celui de
 * `TaikaiWorkflowTest::testFullMarkingThenTieBreakFreezesRanks`.
 */
final class TieBreakControllerTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private TaikaiStateMachine $stateMachine;

    private DrawService $drawService;

    private MarkingService $markingService;

    private TieBreakService $tieBreakService;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->stateMachine = $container->get(TaikaiStateMachine::class);
        $this->drawService = $container->get(DrawService::class);
        $this->markingService = $container->get(MarkingService::class);
        $this->tieBreakService = $container->get(TieBreakService::class);

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

    public function testEditShowsTiedGroup(): void
    {
        $taikai = $this->taikaiAtTieBreakWithATie();
        $taikai->getParticipants();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai));
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table tbody tr')->count();
        self::assertSame(3, $rows, 'les trois archers doivent apparaître');
        self::assertSelectorExists('select[name^="rank["]');
    }

    public function testCanResolveATie(): void
    {
        $taikai = $this->taikaiAtTieBreakWithATie();
        $participants = $taikai->getParticipants();
        // Les archers 2 et 3 (index 1 et 2) sont ex æquo, tous deux au rang 2
        // (LeaderboardService::computeIntermediateRanks fige le même rang pour
        // tout un groupe d'ex æquo) : départager revient à n'en déplacer qu'un.
        $stayed = $participants[1];
        $moved = $participants[2];

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai));
        $token = $this->extractToken($crawler);

        $this->client->request('POST', $this->updatePath($taikai), [
            '_token' => $token,
            'rank' => [(string) $stayed->getId() => '2', (string) $moved->getId() => '3'],
        ]);

        self::assertResponseRedirects('/taikais/'.$taikai->getId());

        $this->entityManager->clear();
        $reloadedStayed = $this->entityManager->getRepository(Participant::class)->find($stayed->getId());
        $reloadedMoved = $this->entityManager->getRepository(Participant::class)->find($moved->getId());
        self::assertSame(2, $reloadedStayed?->getRank());
        self::assertSame(3, $reloadedMoved?->getRank());

        // Seul le rang effectivement modifié est journalisé : `$stayed` valait
        // déjà 2, sa soumission ne produit donc aucun évènement.
        $events = $this->entityManager->getRepository(TaikaiEvent::class)
            ->findBy(['taikai' => $taikai->getId(), 'category' => TaikaiEvent::CATEGORY_TIE_BREAK]);
        self::assertCount(1, $events);
    }

    /** Resoumettre un rang inchangé ne journalise rien de nouveau. */
    public function testUnchangedRankIsNotLogged(): void
    {
        $taikai = $this->taikaiAtTieBreakWithATie();
        $participants = $taikai->getParticipants();

        $this->commitSeeding($this->entityManager);

        $crawler = $this->client->request('GET', $this->editPath($taikai));
        $token = $this->extractToken($crawler);

        // Rangs 1, 2, 2 : identiques à l'état courant, rien ne doit être journalisé.
        $this->client->request('POST', $this->updatePath($taikai), [
            '_token' => $token,
            'rank' => [
                (string) $participants[0]->getId() => '1',
                (string) $participants[1]->getId() => '2',
                (string) $participants[2]->getId() => '2',
            ],
        ]);

        self::assertResponseRedirects('/taikais/'.$taikai->getId());

        self::assertCount(0, $this->entityManager->getRepository(TaikaiEvent::class)
            ->findBy(['taikai' => $taikai->getId(), 'category' => TaikaiEvent::CATEGORY_TIE_BREAK]));
    }

    /** L'écran n'est accessible qu'à l'étape « Tie-Break ». */
    public function testCannotAccessOutsideTieBreakState(): void
    {
        $taikai = $this->readyForMarking(participantCount: 2);

        $this->commitSeeding($this->entityManager);

        $this->client->request('GET', $this->editPath($taikai));

        self::assertResponseStatusCodeSame(403);
    }

    public function testPlainUserCannotAccess(): void
    {
        $taikai = $this->taikaiAtTieBreakWithATie();

        $this->commitSeeding($this->entityManager);
        $this->client->loginUser($this->createUser('outsider@pikaichu.test'));

        $this->client->request('GET', $this->editPath($taikai));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le classement par équipe est couvert au niveau service : construire un
     * taikai « équipes » jusqu'au tie-break demanderait `teams_controller`,
     * pas encore porté (voir MIGRATION.md).
     */
    public function testTeamRanksAreGroupedAndApplied(): void
    {
        $taikai = $this->createTaikai(TaikaiForm::Team);
        $dojo = new Dojo();
        $dojo->setShortname('ktlg')->setName('Club test')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('ktlg');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $teamA = new Team();
        $teamA->setShortname('A')->setIntermediateRank(1)->setRank(1);
        $teamB = new Team();
        $teamB->setShortname('B')->setIntermediateRank(2)->setRank(2);
        $teamC = new Team();
        $teamC->setShortname('C')->setIntermediateRank(2)->setRank(2);
        foreach ([$teamA, $teamB, $teamC] as $team) {
            $participatingDojo->addTeam($team);
            $this->entityManager->persist($team);
        }

        $this->entityManager->flush();

        $groups = $this->tieBreakService->getTeamGroups($taikai);
        self::assertCount(2, $groups);
        self::assertTrue($groups[1]->isTied());

        $this->tieBreakService->applyTeamRanks(
            $taikai,
            [(int) $teamB->getId() => 2, (int) $teamC->getId() => 3],
            $this->admin,
        );

        $this->entityManager->clear();
        $reloadedB = $this->entityManager->getRepository(Team::class)->find($teamB->getId());
        $reloadedC = $this->entityManager->getRepository(Team::class)->find($teamC->getId());
        self::assertSame(2, $reloadedB?->getRank());
        self::assertSame(3, $reloadedC?->getRank());
    }

    private function editPath(Taikai $taikai): string
    {
        return '/taikais/'.$taikai->getId().'/tie-break';
    }

    private function updatePath(Taikai $taikai): string
    {
        return '/taikais/'.$taikai->getId().'/tie-break';
    }

    private function extractToken(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $input = $crawler->filter('input[name="_token"]');
        self::assertGreaterThan(0, $input->count());

        return (string) $input->attr('value');
    }

    /**
     * Trois archers, l'un touche tout, les deux autres manquent tout : rang 1
     * pour le premier, rang 2 ex æquo pour les deux derniers.
     */
    private function taikaiAtTieBreakWithATie(): Taikai
    {
        $taikai = $this->readyForMarking(participantCount: 3);
        $participants = $taikai->getParticipants();

        foreach ($participants as $position => $participant) {
            $status = 0 === $position ? ResultStatus::Hit : ResultStatus::Miss;

            for ($round = 1; $round <= $taikai->getNumRounds(); ++$round) {
                for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
                    $this->markingService->addResult($participant, $status);
                }

                $this->markingService->finalizeRound($participant, $round);
            }
        }

        $this->stateMachine->transitionTo($taikai, TaikaiState::TieBreak, $this->admin);

        return $taikai;
    }

    private function readyForMarking(int $participantCount): Taikai
    {
        $taikai = $this->createTaikai(TaikaiForm::Individual);
        $this->addRequiredStaff($taikai);
        $participatingDojo = $this->addParticipatingDojo($taikai, $participantCount);

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->admin);
        $this->drawService->draw($participatingDojo);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $this->admin);

        return $taikai;
    }

    private function createTaikai(TaikaiForm $form): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname('t-'.bin2hex(random_bytes(4)))
            ->setName('Taikai de test')
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm($form)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(8)
            ->setNumTargets(6)
            ->setTachiSize(3)
            ->setDistributed(false);

        $this->entityManager->persist($taikai);
        $this->entityManager->flush();

        return $taikai;
    }

    private function addRequiredStaff(Taikai $taikai): void
    {
        foreach (StaffRoleCode::requiredForMarking() as $code) {
            $staff = new Staff();
            $staff->setRole($this->findRole($code))->setUser($this->admin);
            $taikai->addStaff($staff);
            $this->entityManager->persist($staff);
        }

        $this->entityManager->flush();
    }

    private function addParticipatingDojo(Taikai $taikai, int $participantCount): ParticipatingDojo
    {
        $dojo = new Dojo();
        $dojo->setShortname('DOJO-'.bin2hex(random_bytes(3)))
            ->setName('Dojo de test')
            ->setCity('Nantes')
            ->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName((string) $dojo->getShortname());
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        for ($i = 1; $i <= $participantCount; ++$i) {
            $participant = new Participant();
            $participant->setFirstname('Archer'.$i)
                ->setLastname('Test')
                ->setClub((string) $dojo->getShortname());
            $participatingDojo->addParticipant($participant);
            $this->entityManager->persist($participant);
        }

        $this->entityManager->flush();

        return $participatingDojo;
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname('Test')
            ->setLastname('User')
            ->setConfirmedAt(new \DateTimeImmutable());
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password123'));

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

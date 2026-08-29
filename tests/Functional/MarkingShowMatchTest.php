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
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Service\MatchService;
use App\Service\TaikaiStateMachine;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Feuille de marque d'un match, portée de `marking#show_match`.
 *
 * Un tournoi à matchs ne crée jamais de score « sans match » (voir
 * `ScoreInitializer`) : la saisie n'est possible que depuis cet écran, pas
 * depuis la feuille de marque générale du taikai.
 */
final class MarkingShowMatchTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UserPasswordHasherInterface $passwordHasher;

    private MatchService $matchService;

    private TaikaiStateMachine $stateMachine;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->matchService = $container->get(MatchService::class);
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

    public function testShowsBothTeamsWithTheirParticipants(): void
    {
        [$taikai, $match] = $this->markingMatch();

        $crawler = $this->client->request(
            'GET',
            \sprintf('/taikais/%d/matches/%d/marking', $taikai->getId(), $match->getId()),
        );

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        // L'appariement du guide des tournois oppose T1 à T4 en demi-finale 1.
        self::assertStringContainsString('T1', $text);
        self::assertStringContainsString('T4', $text);
        self::assertStringContainsString('Archer1', $text);
        self::assertStringContainsString('Archer4', $text);
    }

    /** L'ajout d'une marque doit porter sur le score lié au match, pas sur un score « sans match » inexistant. */
    public function testCanMarkAResultWithinAMatch(): void
    {
        [$taikai, $match] = $this->markingMatch();
        $participant = $match->getTeam(1)?->getParticipants()->first();
        self::assertInstanceOf(Participant::class, $participant);

        $crawler = $this->client->request(
            'GET',
            \sprintf('/taikais/%d/matches/%d/marking', $taikai->getId(), $match->getId()),
        );

        $form = $crawler->filter('form[action="'.\sprintf('/taikais/%d/marking/%d/add', $taikai->getId(), $participant->getId()).'"]')
            ->first()->form();

        $this->client->submit($form, ['status' => 'hit']);

        self::assertResponseRedirects(\sprintf('/taikais/%d/matches/%d/marking', $taikai->getId(), $match->getId()));
        $this->client->followRedirect();
        self::assertSelectorNotExists('.notification.is-danger', 'no error flash expected after marking');

        $this->entityManager->clear();
        $reloadedParticipant = $this->entityManager->getRepository(Participant::class)->find($participant->getId());
        self::assertInstanceOf(Participant::class, $reloadedParticipant);
        $reloadedMatch = $this->entityManager->getRepository(TaikaiMatch::class)->find($match->getId());
        self::assertInstanceOf(TaikaiMatch::class, $reloadedMatch);

        $score = $reloadedParticipant->getScore($reloadedMatch);
        self::assertNotNull($score);
        // Pas encore validée : elle compte comme provisoire, pas comme définitive.
        self::assertSame(1, $score->getIntermediateHits());
        self::assertSame(0, $score->getHits());
    }

    /** @return array{Taikai, TaikaiMatch} */
    private function markingMatch(): array
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

        foreach ([StaffRoleCode::TaikaiAdmin, ...StaffRoleCode::requiredForMarking()] as $code) {
            $staff = new Staff();
            $staff->setRole($this->findRole($code))->setUser($this->admin);
            $taikai->addStaff($staff);
            $this->entityManager->persist($staff);
        }

        $dojo = new Dojo();
        $dojo->setShortname('nantes')->setName('Club de Nantes')->setCity('Nantes')->setCountryCode('FR');
        $this->entityManager->persist($dojo);

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojo)->setDisplayName('nantes');
        $taikai->addParticipatingDojo($participatingDojo);
        $this->entityManager->persist($participatingDojo);

        $teams = [];
        for ($i = 1; $i <= 4; ++$i) {
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

        $this->matchService->createBracket($taikai, $teams);

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $this->admin);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $this->admin);

        $this->commitSeeding($this->entityManager);

        $taikai = $this->entityManager->getRepository(Taikai::class)->find($taikai->getId());
        self::assertInstanceOf(Taikai::class, $taikai);

        foreach ($taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_SEMI_FINAL) as $match) {
            if (1 === $match->getIndex()) {
                return [$taikai, $match];
            }
        }

        self::fail('Semi-final 1 not found');
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

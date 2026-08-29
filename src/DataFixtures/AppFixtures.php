<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
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
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données de démonstration : plusieurs clubs, plusieurs utilisateurs et un
 * taikai par étape de la machine à états (+ un taikai par équipes), pour pouvoir
 * comparer chaque écran à l'identique avec l'application Rails d'origine.
 */
final class AppFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const array ARCHER_NAMES = [
        ['Akira', 'Tanaka'], ['Yuki', 'Sato'], ['Hana', 'Suzuki'], ['Kenji', 'Takahashi'],
        ['Mei', 'Watanabe'], ['Ren', 'Ito'], ['Sora', 'Kobayashi'], ['Aoi', 'Yamamoto'],
        ['Haruto', 'Nakamura'], ['Rin', 'Kato'], ['Sota', 'Yoshida'], ['Yui', 'Yamada'],
    ];

    /**
     * Curseur global (et non par club) dans {@see self::ARCHER_NAMES}, pour que le
     * jeu de données reste identique à chaque rechargement des fixtures — et
     * portable tel quel vers l'équivalent Rails utilisé pour la comparaison.
     */
    private int $archerCursor = 0;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly TaikaiStateMachine $stateMachine,
        private readonly DrawService $drawService,
        private readonly MarkingService $markingService,
    ) {
    }

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [StaffRoleFixtures::class];
    }

    /** @return list<string> */
    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        $admin = $this->createUser('admin@pikaichu.test', 'Admin', 'Pikaichu', true);
        $referee = $this->createUser('juge@pikaichu.test', 'Juge', 'De Cible', false);
        $chairman = $this->createUser('directeur@pikaichu.test', 'Directeur', 'Du Tournoi', false);
        $membre = $this->createUser('membre@pikaichu.test', 'Simple', 'Membre', false);
        foreach ([$admin, $referee, $chairman, $membre] as $user) {
            $manager->persist($user);
        }

        $dojos = [
            'KTLG' => $this->createDojo('KTLG', 'Kyudo Traditionnel Loire et Goulaine', 'Basse-Goulaine'),
            'AKVM' => $this->createDojo('AKVM', 'Association Kyudo Val Maubuée', 'Noisiel'),
            'KKP' => $this->createDojo('KKP', 'Kyudo Kai Paris', 'Paris'),
            'DNK' => $this->createDojo('DNK', 'Dojo Nice Kyudo', 'Nice'),
            'LKC' => $this->createDojo('LKC', 'Lyon Kyudo Club', 'Lyon'),
        ];
        foreach ($dojos as $dojo) {
            $manager->persist($dojo);
        }

        $manager->flush();

        $this->createNewTaikai('DTN-nouveau', $admin, $dojos['KTLG']);
        $this->createRegistrationTaikai('DTN-inscription', $admin, $chairman, $referee, $dojos['AKVM'], $dojos['KKP']);
        $this->createMarkingTaikai('DTN-marquage', $admin, $chairman, $referee, $dojos['DNK']);
        $this->createTieBreakTaikai('DTN-egalite', $admin, $chairman, $referee, $dojos['LKC']);
        $this->createDoneTaikai('DTN-termine', $admin, $chairman, $referee, $dojos['KTLG']);
        $this->createTeamRegistrationTaikai('DTN-equipes', $admin, $chairman, $referee, $dojos['KKP'], $dojos['DNK']);
    }

    /** Étape « Nouveau » : juste créé, encore à configurer. */
    private function createNewTaikai(string $shortname, User $admin, Dojo $dojo): void
    {
        $taikai = $this->createTaikai($shortname, 'Taikai fraîchement créé', TaikaiForm::Individual);
        $this->persistStaff($taikai, StaffRoleCode::TaikaiAdmin, $admin);

        $host = $this->addHostDojo($taikai, $dojo, 'KTLG');
        $this->addArchers($host, 4);

        $this->entityManager->flush();
    }

    /** Étape « Inscription » : staff complet, un club tiré, l'autre non. */
    private function createRegistrationTaikai(
        string $shortname,
        User $admin,
        User $chairman,
        User $referee,
        Dojo $dojoA,
        Dojo $dojoB,
    ): void {
        $taikai = $this->createTaikai($shortname, 'Inscriptions ouvertes, tirage au sort partiel', TaikaiForm::Individual);
        $this->staffRequiredRoles($taikai, $admin, $chairman, $referee);

        $hostA = $this->addHostDojo($taikai, $dojoA, 'AKVM');
        $this->addArchers($hostA, 5);

        $hostB = $this->addHostDojo($taikai, $dojoB, 'KKP');
        $this->addArchers($hostB, 4);

        $this->entityManager->flush();

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $admin);
        $this->drawService->draw($hostA);
    }

    /** Étape « Marquage » : une série entièrement validée, une seconde en cours. */
    private function createMarkingTaikai(
        string $shortname,
        User $admin,
        User $chairman,
        User $referee,
        Dojo $dojo,
    ): void {
        $taikai = $this->createTaikai($shortname, 'Marquage en cours', TaikaiForm::Individual);
        $this->staffRequiredRoles($taikai, $admin, $chairman, $referee);

        $host = $this->addHostDojo($taikai, $dojo, 'DNK');
        $participants = $this->addArchers($host, 4);

        $this->entityManager->flush();

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $admin);
        $this->drawService->draw($host);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $admin);

        // Série 1 : entièrement tirée et validée pour tout le monde.
        $pattern = [ResultStatus::Hit, ResultStatus::Hit, ResultStatus::Miss, ResultStatus::Hit];
        foreach ($participants as $participant) {
            foreach ($pattern as $status) {
                $this->markingService->addResult($participant, $status);
            }

            $this->markingService->finalizeRound($participant, 1);
        }

        // Série 2 : seul le premier participant a commencé, sans valider.
        $this->markingService->addResult($participants[0], ResultStatus::Hit);
        $this->markingService->addResult($participants[0], ResultStatus::Miss);
    }

    /** Étape « Tie-break » : tous ex æquo, à départager depuis l'écran dédié. */
    private function createTieBreakTaikai(
        string $shortname,
        User $admin,
        User $chairman,
        User $referee,
        Dojo $dojo,
    ): void {
        $taikai = $this->createTaikai($shortname, 'Égalité parfaite à départager', TaikaiForm::Individual);
        $this->staffRequiredRoles($taikai, $admin, $chairman, $referee);

        $host = $this->addHostDojo($taikai, $dojo, 'LKC');
        $participants = $this->addArchers($host, 4);

        $this->entityManager->flush();

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $admin);
        $this->drawService->draw($host);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $admin);

        // Tout le monde fait 12/12 : égalité garantie.
        foreach ($participants as $participant) {
            for ($round = 1; $round <= $taikai->getNumRounds(); ++$round) {
                for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
                    $this->markingService->addResult($participant, ResultStatus::Hit);
                }

                $this->markingService->finalizeRound($participant, $round);
            }
        }

        $this->stateMachine->transitionTo($taikai, TaikaiState::TieBreak, $admin);
    }

    /** Étape « Terminé » : résultats distincts, classement final consultable. */
    private function createDoneTaikai(
        string $shortname,
        User $admin,
        User $chairman,
        User $referee,
        Dojo $dojo,
    ): void {
        $taikai = $this->createTaikai($shortname, 'Taikai terminé, résultats disponibles', TaikaiForm::Individual);
        $this->staffRequiredRoles($taikai, $admin, $chairman, $referee);

        $host = $this->addHostDojo($taikai, $dojo, 'KTLG');
        $participants = $this->addArchers($host, 4);

        $this->entityManager->flush();

        $this->stateMachine->transitionTo($taikai, TaikaiState::Registration, $admin);
        $this->drawService->draw($host);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Marking, $admin);

        // Un total de tirs différent par participant, pour un classement sans égalité.
        $missCounts = [0, 1, 2, 3];
        foreach ($participants as $i => $participant) {
            $misses = $missCounts[$i];
            for ($round = 1; $round <= $taikai->getNumRounds(); ++$round) {
                for ($arrow = 1; $arrow <= $taikai->getNumArrows(); ++$arrow) {
                    $status = $misses > 0 && 1 === $round && $arrow <= $misses ? ResultStatus::Miss : ResultStatus::Hit;
                    $this->markingService->addResult($participant, $status);
                }

                $this->markingService->finalizeRound($participant, $round);
            }
        }

        $this->stateMachine->transitionTo($taikai, TaikaiState::TieBreak, $admin);
        $this->stateMachine->transitionTo($taikai, TaikaiState::Done, $admin);
    }

    /** Taikai par équipes, étape « Inscription », pour les écrans spécifiques aux équipes. */
    private function createTeamRegistrationTaikai(
        string $shortname,
        User $admin,
        User $chairman,
        User $referee,
        Dojo $dojoA,
        Dojo $dojoB,
    ): void {
        $taikai = $this->createTaikai($shortname, 'Tournoi par équipes, inscriptions ouvertes', TaikaiForm::Team);
        $this->staffRequiredRoles($taikai, $admin, $chairman, $referee);

        $hostA = $this->addHostDojo($taikai, $dojoA, 'KKP');
        $teamA = $this->createTeam($hostA, 'Paris 1');
        $this->addArchers($hostA, 3, $teamA);

        $hostB = $this->addHostDojo($taikai, $dojoB, 'DNK');
        $teamB = $this->createTeam($hostB, 'Nice 1');
        $this->addArchers($hostB, 3, $teamB);

        $this->entityManager->flush();
    }

    private function createTaikai(string $shortname, string $description, TaikaiForm $form): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname($shortname)
            ->setName('Taikai '.$shortname)
            ->setDescription($description)
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm($form)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(12)
            ->setNumTargets(6)
            ->setTachiSize(3)
            ->setDistributed(false)
            ->setCategory('A');

        $this->entityManager->persist($taikai);

        return $taikai;
    }

    private function staffRequiredRoles(Taikai $taikai, User $admin, User $chairman, User $referee): void
    {
        $this->persistStaff($taikai, StaffRoleCode::TaikaiAdmin, $admin);
        $this->persistStaff($taikai, StaffRoleCode::Chairman, $chairman);
        $this->persistStaff($taikai, StaffRoleCode::ShajoReferee, $admin);
        $this->persistStaff($taikai, StaffRoleCode::TargetReferee, $referee);
    }

    private function addHostDojo(Taikai $taikai, Dojo $dojo, string $displayName): ParticipatingDojo
    {
        $host = new ParticipatingDojo();
        $host->setDojo($dojo)->setDisplayName($displayName);
        $taikai->addParticipatingDojo($host);
        $this->entityManager->persist($host);

        return $host;
    }

    /**
     * @return list<Participant>
     */
    private function addArchers(ParticipatingDojo $host, int $count, ?Team $team = null): array
    {
        $participants = [];
        for ($i = 0; $i < $count; ++$i) {
            [$firstname, $lastname] = self::ARCHER_NAMES[$this->archerCursor % \count(self::ARCHER_NAMES)];
            ++$this->archerCursor;

            $participant = new Participant();
            $participant->setFirstname($firstname)
                ->setLastname($lastname.' '.($i + 1))
                ->setClub((string) $host->getDisplayName());

            if (null !== $team) {
                $participant->setTeam($team)->setIndexInTeam($i + 1);
            }

            $host->addParticipant($participant);
            $this->entityManager->persist($participant);
            $participants[] = $participant;
        }

        return $participants;
    }

    private function createTeam(ParticipatingDojo $host, string $shortname): Team
    {
        $team = new Team();
        $team->setParticipatingDojo($host)->setShortname($shortname);
        $host->addTeam($team);
        $this->entityManager->persist($team);

        return $team;
    }

    private function persistStaff(Taikai $taikai, StaffRoleCode $code, User $user): void
    {
        /** @var StaffRole $role */
        $role = $this->getReference(StaffRoleFixtures::REFERENCE_PREFIX.$code->value, StaffRole::class);

        $staff = new Staff();
        $staff->setRole($role)->setUser($user);
        $taikai->addStaff($staff);

        $this->entityManager->persist($staff);
    }

    private function createUser(string $email, string $firstname, string $lastname, bool $admin): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setAdmin($admin)
            ->setLocale('fr')
            ->setConfirmedAt(new \DateTimeImmutable());

        $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));

        return $user;
    }

    private function createDojo(string $shortname, string $name, string $city): Dojo
    {
        return new Dojo()
            ->setShortname($shortname)
            ->setName($name)
            ->setCity($city)
            ->setCountryCode('FR');
    }
}

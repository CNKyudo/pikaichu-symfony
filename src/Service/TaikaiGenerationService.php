<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\Taikai;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Exception\TaikaiGenerationException;
use App\Repository\StaffRoleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère la 2ᵉ partie (tableau à matchs) d'un taikai 2-en-1, reprise de
 * `Taikai.create_from_2in1` et `Taikai.create_matches` côté Rails.
 *
 * Rails copie le staff avant de construire la correspondance ancien → nouveau
 * club hôte, et affecte donc à chaque membre copié le `participating_dojo_id`
 * de l'**ancien** taikai — une référence qui pointe vers un autre taikai que
 * celui qui vient d'être créé. Rien ne valide qu'un `Staff::participatingDojo`
 * appartienne au même taikai que le staff lui-même (voir `Staff` Rails), donc
 * l'incohérence ne remonte jamais en erreur : elle reste silencieuse. Le
 * portage construit la correspondance en premier et affecte le club hôte
 * remappé, pour obtenir l'intention évidente du code plutôt que ce bug.
 */
final readonly class TaikaiGenerationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Ranker $ranker,
        private MatchService $matchService,
        private StaffRoleRepository $staffRoleRepository,
    ) {
    }

    public function generateFromTwoInOne(Taikai $source, User $currentUser, int $bracketSize): Taikai
    {
        if (!\in_array($bracketSize, MatchService::BRACKET_SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Only 4 or 8 teams are allowed, not %d', $bracketSize));
        }

        if (!$source->isFinalized()) {
            throw new TaikaiGenerationException('taikai.generate.not_finalized');
        }

        $rankedTeams = [];
        foreach ($this->ranker->rank($source->getTeams(), $source, true) as $group) {
            array_push($rankedTeams, ...$group->members);
        }

        if (\count($rankedTeams) < $bracketSize) {
            throw new TaikaiGenerationException('taikai.generate.not_enough_teams', ['%bracket_size%' => $bracketSize]);
        }

        $nonMixedTeams = array_slice(
            array_values(array_filter($rankedTeams, static fn (Team $team): bool => !$team->isMixed())),
            0,
            $bracketSize,
        );

        if (\count($nonMixedTeams) < $bracketSize) {
            throw new TaikaiGenerationException('taikai.generate.not_enough_non_mixed_teams', [
                '%bracket_size%' => $bracketSize,
                '%available%' => \count($nonMixedTeams),
            ]);
        }

        $newTaikai = $this->buildTaikaiShell($source);
        $this->addAdminStaff($newTaikai, $currentUser);
        $this->entityManager->persist($newTaikai);

        $dojoMapping = $this->copyParticipatingDojos($source, $newTaikai);
        $this->copyStaff($source, $newTaikai, $currentUser, $dojoMapping);
        $newTeams = $this->copyTeams($nonMixedTeams, $dojoMapping);

        $this->matchService->createBracket($newTaikai, $newTeams);

        $this->entityManager->flush();

        return $newTaikai;
    }

    private function buildTaikaiShell(Taikai $source): Taikai
    {
        $taikai = new Taikai();
        $taikai->setShortname($source->getShortname().'-part2')
            ->setName($source->getName().' partie 2')
            ->setStartDate($source->getStartDate())
            ->setEndDate($source->getEndDate())
            ->setTotalNumArrows(4)
            ->setNumTargets($source->getNumTargets())
            ->setTachiSize($source->getTachiSize())
            ->setDistributed($source->isDistributed())
            ->setCategory($source->getCategory())
            ->setForm(TaikaiForm::Matches)
            ->setScoring($source->getScoring());

        return $taikai;
    }

    /** Le créateur devient administrateur du taikai généré, comme pour toute création de taikai. */
    private function addAdminStaff(Taikai $taikai, User $currentUser): void
    {
        $staff = new Staff();
        $staff->setRole($this->staffRoleRepository->getByCode(StaffRoleCode::TaikaiAdmin))
            ->setUser($currentUser);
        $taikai->addStaff($staff);
    }

    /** @return array<int, ParticipatingDojo> ancien id de club hôte vers sa copie */
    private function copyParticipatingDojos(Taikai $source, Taikai $newTaikai): array
    {
        $mapping = [];
        foreach ($source->getParticipatingDojos() as $participatingDojo) {
            $copy = new ParticipatingDojo();
            $copy->setDojo($participatingDojo->getDojo())
                ->setDisplayName($participatingDojo->getDisplayName());
            $newTaikai->addParticipatingDojo($copy);
            $this->entityManager->persist($copy);

            $mapping[(int) $participatingDojo->getId()] = $copy;
        }

        return $mapping;
    }

    /**
     * @param array<int, ParticipatingDojo> $dojoMapping
     */
    private function copyStaff(Taikai $source, Taikai $newTaikai, User $currentUser, array $dojoMapping): void
    {
        foreach ($source->getStaffs() as $staff) {
            // Déjà copié par `addAdminStaff()`.
            if ($staff->getUser() === $currentUser && StaffRoleCode::TaikaiAdmin === $staff->getRole()?->getCode()) {
                continue;
            }

            $copy = new Staff();
            $role = $staff->getRole();
            if (null !== $role) {
                $copy->setRole($role);
            }

            $copy->setUser($staff->getUser())
                ->setFirstname($staff->getFirstname())
                ->setLastname($staff->getLastname());

            $oldParticipatingDojo = $staff->getParticipatingDojo();
            if (null !== $oldParticipatingDojo) {
                $copy->setParticipatingDojo($dojoMapping[(int) $oldParticipatingDojo->getId()] ?? null);
            }

            $newTaikai->addStaff($copy);
            $this->entityManager->persist($copy);
        }
    }

    /**
     * Copie les équipes qualifiées et leurs participants, dans leur ordre de
     * classement (meilleure en tête) : c'est cet ordre que `MatchService::createBracket()`
     * utilise pour composer le tableau.
     *
     * @param list<Team>                    $rankedTeams équipes classées, non mixtes, déjà limitées à la taille du tableau
     * @param array<int, ParticipatingDojo> $dojoMapping
     *
     * @return list<Team>
     */
    private function copyTeams(array $rankedTeams, array $dojoMapping): array
    {
        $newTeams = [];
        foreach ($rankedTeams as $index => $team) {
            $oldParticipatingDojo = $team->getParticipatingDojo();
            if (null === $oldParticipatingDojo) {
                continue;
            }

            $newParticipatingDojo = $dojoMapping[(int) $oldParticipatingDojo->getId()] ?? null;
            if (null === $newParticipatingDojo) {
                continue;
            }

            $copy = new Team();
            $copy->setShortname($team->getShortname())->setIndex($index + 1);
            $newParticipatingDojo->addTeam($copy);
            $this->entityManager->persist($copy);

            foreach ($team->getParticipants() as $participant) {
                $participantCopy = new Participant();
                $participantCopy->setKyudojin($participant->getKyudojin())
                    ->setFirstname($participant->getFirstname())
                    ->setLastname($participant->getLastname())
                    ->setClub($participant->getClub());
                $newParticipatingDojo->addParticipant($participantCopy);
                $copy->addParticipant($participantCopy);
                $this->entityManager->persist($participantCopy);
            }

            $newTeams[] = $copy;
        }

        return $newTeams;
    }
}

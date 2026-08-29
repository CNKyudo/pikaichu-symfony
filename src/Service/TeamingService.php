<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Composition des équipes d'un club hôte, reprise de `teaming_controller`.
 *
 * L'affectation à une équipe se fait par sélection plutôt que par
 * glisser-déposer, et place toujours le participant en fin d'équipe plutôt
 * qu'à une position précise ; en revanche `reorderParticipant()` porte bien
 * `participants#reorder`, le glisser-déposer qui réordonne au sein d'une
 * équipe déjà formée (voir `assets/controllers/reorder_controller.js`).
 */
final readonly class TeamingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Crée une équipe vide à partir d'un simple nom, comme `teaming#create_team`.
     * Les violations éventuelles (nom vide ou déjà pris) restent à la charge de
     * l'appelant pour construire le message d'alerte.
     */
    public function createTeam(ParticipatingDojo $participatingDojo, ?string $shortname): ConstraintViolationListInterface
    {
        $team = new Team();
        $team->setShortname($shortname);

        $participatingDojo->addTeam($team);

        $violations = $this->validator->validate($team);
        if (\count($violations) > 0) {
            $participatingDojo->removeTeam($team);

            return $violations;
        }

        $this->entityManager->persist($team);
        $this->entityManager->flush();

        return $violations;
    }

    /**
     * Affecte un participant à une équipe, en fin de liste, ou le détache si
     * `$team` est `null`. Reprend `teaming#move` sans le positionnement par
     * glisser-déposer.
     */
    public function moveParticipant(Participant $participant, ?Team $team): void
    {
        $participant->setTeam($team);
        $participant->setIndexInTeam(null === $team ? null : $this->nextIndexInTeam($team));

        $this->entityManager->flush();
    }

    /** Détache tous les participants du club hôte de leur équipe, comme `teaming#clear`. */
    public function clear(ParticipatingDojo $participatingDojo): void
    {
        foreach ($participatingDojo->getParticipants() as $participant) {
            $participant->setTeam(null);
            $participant->setIndexInTeam(null);
        }

        $this->entityManager->flush();
    }

    /**
     * Répartit aléatoirement les participants non affectés en équipes mixtes de
     * la taille d'un tachi, comme `teaming#form_randomly`.
     */
    public function formRandomly(ParticipatingDojo $participatingDojo, string $prefix): void
    {
        $tachiSize = max(1, $participatingDojo->getTaikai()?->getTachiSize() ?? 1);

        $unteamed = $participatingDojo->getUnteamedParticipants();
        shuffle($unteamed);

        foreach (array_chunk($unteamed, $tachiSize) as $groupIndex => $group) {
            $team = new Team();
            $team->setShortname($prefix.($groupIndex + 1))->setMixed(true);
            $participatingDojo->addTeam($team);
            $this->entityManager->persist($team);

            foreach ($group as $participantIndex => $participant) {
                $participant->setIndexInTeam($participantIndex + 1);
                $team->addParticipant($participant);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Replace un participant à une position précise au sein de son équipe,
     * en décalant les autres. Porte `participants#reorder`
     * (`acts_as_list#insert_at`, `scope: :team`) ; `$position` est 1-indexée,
     * comme côté Rails.
     */
    public function reorderParticipant(Team $team, Participant $participant, int $position): void
    {
        $members = array_values(array_filter(
            iterator_to_array($team->getParticipants()),
            static fn (Participant $member): bool => $member !== $participant,
        ));

        $position = max(1, min($position, \count($members) + 1));
        array_splice($members, $position - 1, 0, [$participant]);

        // Un index unique partiel `(team_id, index_in_team)` interdit toute
        // collision, même transitoire : Doctrine n'écrit pas forcément les
        // `UPDATE` dans l'ordre de ce tableau. On vide donc tous les index de
        // l'équipe avant de réécrire les valeurs finales, comme
        // `TaikaiStateMachine::recordTransition()` le fait déjà pour
        // `most_recent` (voir MIGRATION.md).
        foreach ($members as $member) {
            $member->setIndexInTeam(null);
        }

        $this->entityManager->flush();

        foreach ($members as $index => $member) {
            $member->setIndexInTeam($index + 1);
        }

        $this->entityManager->flush();
    }

    private function nextIndexInTeam(Team $team): int
    {
        $max = 0;
        foreach ($team->getParticipants() as $member) {
            $max = max($max, $member->getIndexInTeam() ?? 0);
        }

        return $max + 1;
    }
}

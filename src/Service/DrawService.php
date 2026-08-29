<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Team;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tirage au sort de l'ordre de passage d'un club hôte.
 *
 * Reprend `ParticipatingDojo#draw` de l'application Rails. Le tirage n'est possible
 * qu'à l'étape « Enregistrement ».
 */
final readonly class DrawService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Effectue le tirage au sort.
     *
     * @return string|null clé de traduction de l'erreur, ou null en cas de succès
     */
    public function draw(ParticipatingDojo $participatingDojo): ?string
    {
        $taikai = $participatingDojo->getTaikai();
        if (null === $taikai) {
            return 'draw.no_taikai';
        }

        if (!$taikai->isState(TaikaiState::Registration)) {
            return 'draw.no_change_if_taikai_is_marking';
        }

        $error = null;
        switch ($taikai->getForm()) {
            case TaikaiForm::Individual:
                $this->drawIndividual($participatingDojo);
                break;
            case TaikaiForm::Team:
                $this->drawTeams($participatingDojo);
                break;
            case TaikaiForm::TwoInOne:
                // Seul le 2-en-1 peut refuser le tirage, si des archers sont sans équipe.
                $error = $this->drawTwoInOne($participatingDojo);
                break;
            default:
                // Un tournoi à matchs n'a pas de tirage : le bracket fait foi.
                break;
        }

        if (null !== $error) {
            return $error;
        }

        $this->entityManager->flush();

        return null;
    }

    /**
     * En individuel, on mélange directement les participants.
     */
    private function drawIndividual(ParticipatingDojo $participatingDojo): void
    {
        $participants = array_values($participatingDojo->getParticipants()->toArray());

        // Un re-tirage doit d'abord vider les index existants : sinon, réattribuer
        // directement de nouvelles valeurs peut heurter la contrainte d'unicité
        // d'un autre participant qui détient encore temporairement cette valeur.
        $this->clearIndexes($participants);

        shuffle($participants);
        foreach ($participants as $position => $participant) {
            $participant->setIndex($position + 1);
        }
    }

    /**
     * En équipes, on mélange les équipes puis on renumérote leurs archers.
     */
    private function drawTeams(ParticipatingDojo $participatingDojo): void
    {
        $teams = array_values($participatingDojo->getTeams()->toArray());

        $this->clearIndexes($teams);

        shuffle($teams);
        foreach ($teams as $position => $team) {
            $team->setIndex($position + 1);
        }

        $this->reindexParticipantsFromTeams($participatingDojo);
    }

    /**
     * En 2-en-1, tous les archers doivent être affectés à une équipe. Les équipes
     * complètes sont tirées en premier, les incomplètes viennent ensuite.
     */
    private function drawTwoInOne(ParticipatingDojo $participatingDojo): ?string
    {
        if ([] !== $participatingDojo->getUnteamedParticipants()) {
            return 'draw.unteamed_participants';
        }

        $tachiSize = $participatingDojo->getTaikai()?->getTachiSize() ?? 0;

        $complete = [];
        $incomplete = [];
        foreach ($participatingDojo->getTeams() as $team) {
            if ($team->getParticipants()->count() >= $tachiSize) {
                $complete[] = $team;
            } else {
                $incomplete[] = $team;
            }
        }

        $this->clearIndexes([...$complete, ...$incomplete]);

        shuffle($complete);
        shuffle($incomplete);

        $position = 0;
        foreach ([...$complete, ...$incomplete] as $team) {
            /** @var Team $team */
            $team->setIndex(++$position);
        }

        $this->reindexParticipantsFromTeams($participatingDojo);

        return null;
    }

    /**
     * Renumérote les archers en suivant l'ordre des équipes puis l'ordre interne
     * à chaque équipe.
     */
    private function reindexParticipantsFromTeams(ParticipatingDojo $participatingDojo): void
    {
        $teams = array_values($participatingDojo->getTeams()->toArray());
        usort(
            $teams,
            static fn (Team $a, Team $b): int => ($a->getIndex() ?? \PHP_INT_MAX) <=> ($b->getIndex() ?? \PHP_INT_MAX)
        );

        $this->clearIndexes(array_values($participatingDojo->getParticipants()->toArray()));

        $position = 0;
        foreach ($teams as $team) {
            foreach ($team->getParticipants() as $participant) {
                $participant->setIndex(++$position);
            }
        }

        // Les archers sans équipe perdent leur ordre de passage (déjà à null ci-dessus).
    }

    /**
     * Vide et persiste immédiatement l'index d'une liste d'entités (participants ou
     * équipes) avant de leur en attribuer de nouveaux : un re-tirage ne peut pas
     * réattribuer directement les valeurs, sous peine de heurter la contrainte
     * d'unicité d'une autre entité qui détient encore temporairement cette valeur.
     *
     * @param list<Participant|Team> $entities
     */
    private function clearIndexes(array $entities): void
    {
        foreach ($entities as $entity) {
            $entity->setIndex(null);
        }

        $this->entityManager->flush();
    }
}

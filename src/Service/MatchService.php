<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Construction du tableau final et désignation des vainqueurs.
 *
 * Reprend `Taikai.create_matches`, `Match#select_winner` et le `before_update`
 * de `Match` (réaffectation manuelle des équipes) de l'application Rails, y
 * compris l'ordre d'appariement issu du guide des tournois de novembre 2021.
 */
final readonly class MatchService
{
    /** Tailles de tableau supportées. */
    public const array BRACKET_SIZES = [4, 8];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScoreInitializer $scoreInitializer,
    ) {
    }

    /**
     * Crée le tableau final pour 4 ou 8 équipes.
     *
     * @param list<Team> $teams équipes classées, meilleure en tête
     */
    public function createBracket(Taikai $taikai, array $teams): void
    {
        $count = \count($teams);
        if (!\in_array($count, self::BRACKET_SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Only 4 or 8 teams are allowed, not %d', $count));
        }

        // Appariements du guide des tournois : le premier rencontre le dernier, etc.
        [$pairing, $level] = 8 === $count
            ? [[0, 7, 4, 3, 2, 5, 6, 1], TaikaiMatch::LEVEL_QUARTER_FINAL]
            : [[0, 3, 2, 1], TaikaiMatch::LEVEL_SEMI_FINAL];

        $index = 0;
        foreach (array_chunk($pairing, 2) as $pair) {
            $this->createMatch($taikai, ++$index, $level, $teams[$pair[0]], $teams[$pair[1]]);
        }

        // Un tableau de 8 a besoin de demi-finales encore vides.
        if (8 === $count) {
            $this->createMatch($taikai, 1, TaikaiMatch::LEVEL_SEMI_FINAL);
            $this->createMatch($taikai, 2, TaikaiMatch::LEVEL_SEMI_FINAL);
        }

        // Grande finale (index 1) et petite finale (index 2).
        $this->createMatch($taikai, TaikaiMatch::INDEX_GRAND_FINAL, TaikaiMatch::LEVEL_FINAL);
        $this->createMatch($taikai, TaikaiMatch::INDEX_SMALL_FINAL, TaikaiMatch::LEVEL_FINAL);

        $this->entityManager->flush();
    }

    private function createMatch(
        Taikai $taikai,
        int $index,
        int $level,
        ?Team $team1 = null,
        ?Team $team2 = null,
    ): TaikaiMatch {
        $match = new TaikaiMatch();
        $match->setTaikai($taikai)
            ->setIndex($index)
            ->setLevel($level)
            ->setTeam1($team1)
            ->setTeam2($team2);

        $taikai->addMatch($match);
        $this->entityManager->persist($match);

        return $match;
    }

    /**
     * Réaffecte manuellement les équipes d'une rencontre, porté du `before_update`
     * du modèle `Match` Rails : change d'équipe supprime le score et les résultats
     * de l'ancienne (refusé si déjà validés), et initialise ceux de la nouvelle.
     *
     * @return string|null clé de traduction de l'erreur, ou null en cas de succès
     */
    public function updateTeams(TaikaiMatch $match, ?Team $team1, ?Team $team2): ?string
    {
        if ($team1 !== $match->getTeam1()) {
            $error = $this->reassignTeam($match, 1, $team1);
            if (null !== $error) {
                return $error;
            }
        }

        if ($team2 !== $match->getTeam2()) {
            $error = $this->reassignTeam($match, 2, $team2);
            if (null !== $error) {
                return $error;
            }
        }

        $this->entityManager->flush();

        return null;
    }

    private function reassignTeam(TaikaiMatch $match, int $slot, ?Team $newTeam): ?string
    {
        $oldTeam = $match->getTeam($slot);
        if (null !== $oldTeam) {
            if ($oldTeam->getScore($match)?->isFinalized() ?? false) {
                return 'match.cant_change_teams_if_results_exist';
            }

            $oldScore = $oldTeam->getScore($match);
            if (null !== $oldScore) {
                $this->entityManager->remove($oldScore);
            }

            foreach ($oldTeam->getParticipants() as $participant) {
                $participantScore = $participant->getScore($match);
                if (null !== $participantScore) {
                    $this->entityManager->remove($participantScore);
                }
            }
        }

        $match->setTeam($slot, $newTeam);

        if (null !== $newTeam) {
            $this->scoreInitializer->createTeamScore($newTeam, $match);
            foreach ($newTeam->getParticipants() as $participant) {
                $this->scoreInitializer->createParticipantScore($participant, $match);
            }
        }

        return null;
    }

    /**
     * Désigne l'équipe gagnante et fait progresser le tableau.
     *
     * Le vainqueur monte au niveau supérieur ; en demi-finale, le perdant bascule
     * vers la petite finale.
     *
     * @param int $winner 1 ou 2
     *
     * @return string|null clé de traduction de l'erreur, ou null en cas de succès
     */
    public function selectWinner(TaikaiMatch $match, int $winner): ?string
    {
        if (1 !== $winner && 2 !== $winner) {
            throw new \InvalidArgumentException('Winner can be only 1 or 2');
        }

        $taikai = $match->getTaikai();
        if (null === $taikai) {
            return 'match.no_taikai';
        }

        $level = $match->getLevel();
        $index = $match->getIndex();
        $loser = 1 === $winner ? 2 : 1;

        // Match de destination du vainqueur, au niveau immédiatement supérieur.
        $nextMatch = null;
        if ($level > TaikaiMatch::LEVEL_FINAL) {
            $nextMatch = $this->findMatch($taikai, $level - 1, intdiv($index - 1, 2) + 1);
            if (null !== $nextMatch && $nextMatch->hasDefinedResults()) {
                return 'match.defined_results_for_target_match';
            }
        }

        // Depuis une demi-finale, le perdant descend en petite finale.
        $smallFinal = null;
        if (TaikaiMatch::LEVEL_SEMI_FINAL === $level) {
            $smallFinal = $this->findMatch($taikai, TaikaiMatch::LEVEL_FINAL, TaikaiMatch::INDEX_SMALL_FINAL);
            if (null !== $smallFinal && $smallFinal->hasDefinedResults()) {
                return 'match.defined_results_for_target_match';
            }
        }

        $match->setWinner($winner);

        // Les matchs d'index pair alimentent la place 2 du match suivant.
        $slot = 0 === $index % 2 ? 2 : 1;

        if (null !== $nextMatch) {
            $this->assignTeam($nextMatch, $slot, $match->getTeam($winner));
        }

        if (null !== $smallFinal) {
            $this->assignTeam($smallFinal, $slot, $match->getTeam($loser));
        }

        // Le tachi de la rencontre est terminé dès qu'elle est tranchée.
        foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
            foreach ($participatingDojo->getTachis() as $tachi) {
                if ($tachi->getMatch() === $match) {
                    $tachi->setFinished(true);
                }
            }
        }

        $this->entityManager->flush();

        return null;
    }

    /**
     * Place une équipe dans une rencontre et lui crée ses feuilles de marque.
     */
    private function assignTeam(TaikaiMatch $match, int $slot, ?Team $team): void
    {
        if (null === $team || $match->getTeam($slot) === $team) {
            return;
        }

        $match->setTeam($slot, $team);

        $this->scoreInitializer->createTeamScore($team, $match);
        foreach ($team->getParticipants() as $participant) {
            if (null === $participant->getScore($match)) {
                $this->scoreInitializer->createParticipantScore($participant, $match);
            }
        }
    }

    private function findMatch(Taikai $taikai, int $level, int $index): ?TaikaiMatch
    {
        foreach ($taikai->getMatches() as $match) {
            if ($match->getLevel() === $level && $match->getIndex() === $index) {
                return $match;
            }
        }

        return null;
    }
}

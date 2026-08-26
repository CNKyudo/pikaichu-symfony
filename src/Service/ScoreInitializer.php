<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Result;
use App\Entity\Score;
use App\Entity\Tachi;
use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Entity\Team;
use App\Enum\TaikaiForm;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prépare et démonte les feuilles de marque d'un taikai.
 *
 * Appelé par la machine à états lors du passage « Enregistrement » ↔ « Marquage ».
 * Reprend `Taikai#create_tachi_and_scores` / `#delete_tachis_and_scores` côté Rails.
 */
final readonly class ScoreInitializer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Crée les tachis, les scores vides et toutes les flèches à saisir.
     */
    public function initialize(Taikai $taikai): void
    {
        foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
            $this->createTachis($participatingDojo);
        }

        if (TaikaiForm::Matches === $taikai->getForm()) {
            foreach ($taikai->getMatches() as $match) {
                $this->initializeMatch($match);
            }
        } else {
            foreach ($taikai->getTeams() as $team) {
                $this->createTeamScore($team);
            }

            foreach ($taikai->getParticipants() as $participant) {
                $this->createParticipantScore($participant);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Supprime tachis et scores pour permettre de revenir à l'enregistrement.
     */
    public function reset(Taikai $taikai): void
    {
        foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
            foreach ($participatingDojo->getTachis() as $tachi) {
                $this->entityManager->remove($tachi);
            }

            $participatingDojo->getTachis()->clear();
        }

        foreach ($taikai->getParticipants() as $participant) {
            foreach ($participant->getScores() as $score) {
                $this->entityManager->remove($score);
            }

            $participant->getScores()->clear();
        }

        foreach ($taikai->getTeams() as $team) {
            foreach ($team->getScores() as $score) {
                $this->entityManager->remove($score);
            }

            $team->getScores()->clear();
        }

        if (TaikaiForm::Matches === $taikai->getForm()) {
            foreach ($taikai->getMatches() as $match) {
                $match->setWinner(null);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Crée les tachis d'un club hôte.
     *
     * Hors tournoi à matchs, un tachi par groupe de tir et par série. En tournoi à
     * matchs, un tachi par rencontre, les deux finales étant placées en fin de liste.
     */
    private function createTachis(ParticipatingDojo $participatingDojo): void
    {
        $taikai = $participatingDojo->getTaikai();
        if (null === $taikai) {
            return;
        }

        if (TaikaiForm::Matches !== $taikai->getForm()) {
            $groupCount = \count($participatingDojo->getTachiGroups());
            for ($round = 1; $round <= $taikai->getNumRounds(); ++$round) {
                for ($index = 1; $index <= $groupCount; ++$index) {
                    $this->persistTachi($participatingDojo, $round, $index);
                }
            }

            return;
        }

        $index = 0;
        foreach ([TaikaiMatch::LEVEL_QUARTER_FINAL, TaikaiMatch::LEVEL_SEMI_FINAL] as $level) {
            foreach ($taikai->getMatchesAtLevel($level) as $match) {
                $this->persistTachi($participatingDojo, 1, ++$index, $match);
            }
        }

        $finals = $taikai->getMatchesAtLevel(TaikaiMatch::LEVEL_FINAL);
        $grandFinal = $finals[0] ?? null;
        $smallFinal = $finals[1] ?? null;

        if (null !== $smallFinal) {
            $this->persistTachi($participatingDojo, 1, $index + 2, $smallFinal);
        }

        if (null !== $grandFinal) {
            $this->persistTachi($participatingDojo, 1, $index + 3, $grandFinal);
        }
    }

    private function persistTachi(
        ParticipatingDojo $participatingDojo,
        int $round,
        int $index,
        ?TaikaiMatch $match = null,
    ): void {
        $tachi = new Tachi();
        $tachi->setParticipatingDojo($participatingDojo)
            ->setRound($round)
            ->setIndex($index)
            ->setMatch($match);

        $participatingDojo->addTachi($tachi);
        $this->entityManager->persist($tachi);
    }

    /**
     * Crée le score d'un participant et les flèches vides correspondantes.
     */
    public function createParticipantScore(Participant $participant, ?TaikaiMatch $match = null): Score
    {
        if ($participant->hasDefinedResults($match)) {
            throw new \LogicException(\sprintf(
                'Defined result(s) already exist(s) for participant %d (%s)',
                (int) $participant->getId(),
                $participant->getDisplayName(),
            ));
        }

        $taikai = $participant->getTaikai();
        $numRounds = $taikai?->getNumRounds() ?? 1;
        $numArrows = $taikai?->getNumArrows() ?? Taikai::ARROWS_PER_ROUND;

        $score = new Score();
        $score->setParticipant($participant)->setMatch($match);
        $participant->addScore($score);
        $this->entityManager->persist($score);

        for ($round = 1; $round <= $numRounds; ++$round) {
            for ($index = 1; $index <= $numArrows; ++$index) {
                $result = new Result();
                $result->setScore($score)
                    ->setMatch($match)
                    ->setRound($round)
                    ->setIndex($index);

                $score->addResult($result);
                $this->entityManager->persist($result);
            }
        }

        return $score;
    }

    public function createTeamScore(Team $team, ?TaikaiMatch $match = null): Score
    {
        $score = new Score();
        $score->setTeam($team)->setMatch($match);
        $team->addScore($score);
        $this->entityManager->persist($score);

        return $score;
    }

    /**
     * Prépare les scores des deux équipes engagées dans une rencontre.
     */
    public function initializeMatch(TaikaiMatch $match): void
    {
        foreach ([1, 2] as $index) {
            $team = $match->getTeam($index);
            if (null === $team) {
                continue;
            }

            $this->createTeamScore($team, $match);
            foreach ($team->getParticipants() as $participant) {
                $this->createParticipantScore($participant, $match);
            }
        }
    }
}

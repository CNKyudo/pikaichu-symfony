<?php

declare(strict_types=1);

namespace App\Entity;

use App\DTO\ScoreValue;
use App\Repository\ScoreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Le score d'un participant ou d'une équipe, éventuellement rattaché à un match.
 *
 * Les colonnes `hits`/`value` ne comptent que les flèches validées, tandis que
 * `intermediate_hits`/`intermediate_value` comptent aussi les flèches marquées mais
 * pas encore validées : c'est ce qui permet d'afficher un classement provisoire.
 */
#[ORM\Entity(repositoryClass: ScoreRepository::class)]
#[ORM\Table(name: 'scores')]
#[UniqueConstraint(name: 'by_participant_id', columns: ['participant_id', 'match_id'])]
#[UniqueConstraint(name: 'by_team_id_match_id', columns: ['team_id', 'match_id'])]
#[ORM\HasLifecycleCallbacks]
class Score implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Participant::class, inversedBy: 'scores')]
    #[ORM\JoinColumn(name: 'participant_id', nullable: true, onDelete: 'CASCADE')]
    private ?Participant $participant = null;

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'scores')]
    #[ORM\JoinColumn(name: 'team_id', nullable: true, onDelete: 'CASCADE')]
    private ?Team $team = null;

    #[ORM\ManyToOne(targetEntity: TaikaiMatch::class)]
    #[ORM\JoinColumn(name: 'match_id', nullable: true, onDelete: 'CASCADE')]
    private ?TaikaiMatch $match = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\NotNull]
    private int $hits = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\NotNull]
    private int $value = 0;

    #[ORM\Column(name: 'intermediate_hits', options: ['default' => 0])]
    private int $intermediateHits = 0;

    #[ORM\Column(name: 'intermediate_value', options: ['default' => 0])]
    private int $intermediateValue = 0;

    /** @var Collection<int, Result> */
    #[ORM\OneToMany(targetEntity: Result::class, mappedBy: 'score', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['round' => 'ASC', 'index' => 'ASC'])]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): static
    {
        $this->participant = $participant;

        return $this;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;

        return $this;
    }

    public function getMatch(): ?TaikaiMatch
    {
        return $this->match;
    }

    public function setMatch(?TaikaiMatch $match): static
    {
        $this->match = $match;

        return $this;
    }

    public function getTaikai(): ?Taikai
    {
        return $this->team?->getTaikai() ?? $this->participant?->getTaikai();
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getIntermediateHits(): int
    {
        return $this->intermediateHits;
    }

    public function getIntermediateValue(): int
    {
        return $this->intermediateValue;
    }

    /** @return Collection<int, Result> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function addResult(Result $result): static
    {
        if (!$this->results->contains($result)) {
            $this->results->add($result);
            $result->setScore($this);
        }

        return $this;
    }

    /**
     * Flèches d'une série donnée.
     *
     * @return list<Result>
     */
    public function getResultsForRound(int $round): array
    {
        return array_values(
            $this->results
                ->filter(static fn (Result $r): bool => $r->getRound() === $round)
                ->toArray()
        );
    }

    /** Première flèche non encore marquée, ou null si tout est marqué. */
    public function getFirstEmptyResult(): ?Result
    {
        foreach ($this->results as $result) {
            if ($result->isEmpty()) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Score utilisé pour les classements.
     *
     * @param bool $validated true pour ne compter que les flèches validées,
     *                        false pour inclure les flèches simplement marquées
     */
    public function toScoreValue(bool $validated = true): ScoreValue
    {
        return $validated
            ? new ScoreValue(hits: $this->hits, value: $this->value)
            : new ScoreValue(hits: $this->intermediateHits, value: $this->intermediateValue);
    }

    /**
     * Recalcule les compteurs à partir des flèches.
     * Équivalent de `recalculate_individual_score` côté Rails.
     */
    public function recalculateFromResults(): void
    {
        $intermediateHits = 0;
        $intermediateValue = 0;
        $hits = 0;
        $value = 0;

        foreach ($this->results as $result) {
            if (!$result->isHit()) {
                continue;
            }

            ++$intermediateHits;
            $intermediateValue += $result->getValue() ?? 0;

            if ($result->isFinal()) {
                ++$hits;
                $value += $result->getValue() ?? 0;
            }
        }

        $this->hits = $hits;
        $this->value = $value;
        $this->intermediateHits = $intermediateHits;
        $this->intermediateValue = $intermediateValue;
    }

    /**
     * Recalcule le score d'équipe comme la somme des scores de ses membres.
     * Équivalent de `recalculate_team_score` côté Rails.
     */
    public function recalculateFromTeam(): void
    {
        if (null === $this->team) {
            return;
        }

        $hits = 0;
        $value = 0;
        $intermediateHits = 0;
        $intermediateValue = 0;

        foreach ($this->team->getParticipants() as $participant) {
            $score = $participant->getScore($this->match);
            if (null === $score) {
                continue;
            }

            $hits += $score->getHits();
            $value += $score->getValue();
            $intermediateHits += $score->getIntermediateHits();
            $intermediateValue += $score->getIntermediateValue();
        }

        $this->hits = $hits;
        $this->value = $value;
        $this->intermediateHits = $intermediateHits;
        $this->intermediateValue = $intermediateValue;
    }

    /** La série précédant celle de cette flèche est-elle validée ? */
    public function isPreviousRoundFinalized(Result $result): bool
    {
        $round = $result->getRound();
        if (null === $round || 1 === $round) {
            return true;
        }

        return array_all($this->getResultsForRound($round - 1), fn (Result $previous): bool => $previous->isFinal());
    }

    public function isRoundFinalized(int $round): bool
    {
        $results = $this->getResultsForRound($round);
        if ([] === $results) {
            return false;
        }

        return array_all($results, fn ($result) => $result->isFinal());
    }

    /**
     * Un score d'équipe est validé quand tous ses membres le sont ; un score
     * individuel quand toutes ses flèches sont validées.
     */
    public function isFinalized(): bool
    {
        if (null !== $this->team) {
            foreach ($this->team->getParticipants() as $participant) {
                if (!($participant->getScore($this->match)?->isFinalized() ?? false)) {
                    return false;
                }
            }

            return true;
        }

        if ($this->results->isEmpty()) {
            return false;
        }

        foreach ($this->results as $result) {
            if (!$result->isFinal()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le score est-il en cours de saisie ? Reprend la logique `marking?` du modèle
     * Rails : il reste des flèches à marquer, et soit rien n'est encore marqué, soit
     * tout ce qui est marqué est validé, soit la série en cours est incomplète.
     */
    public function isMarking(): bool
    {
        $total = $this->results->count();
        $marked = 0;
        $finalized = 0;

        foreach ($this->results as $result) {
            if ($result->isMarked()) {
                ++$marked;
            }

            if ($result->isFinal()) {
                ++$finalized;
            }
        }

        if ($marked === $total) {
            return false;
        }

        $numArrows = $this->getTaikai()?->getNumArrows() ?? Taikai::ARROWS_PER_ROUND;

        return 0 === $marked
            || $finalized === $marked
            || 0 !== $marked % $numArrows;
    }

    /**
     * Un score porte sur un participant OU une équipe, jamais les deux.
     * Reprend la validation `team_xor_participant` du modèle Rails.
     */
    #[Assert\Callback]
    public function validateTeamXorParticipant(ExecutionContextInterface $context): void
    {
        if ((null === $this->team) === (null === $this->participant)) {
            $context->buildViolation('score.team_xor_participant')->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->value.' / '.$this->hits;
    }
}

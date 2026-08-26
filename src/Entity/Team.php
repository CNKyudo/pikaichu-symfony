<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une équipe, rattachée à un club hôte.
 */
#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'teams')]
#[UniqueConstraint(name: 'teams_by_participating_dojo_index', columns: ['participating_dojo_id', 'index'])]
#[UniqueConstraint(name: 'by_teams_shortname', columns: ['participating_dojo_id', 'shortname'])]
#[ORM\HasLifecycleCallbacks]
class Team implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ParticipatingDojo::class, inversedBy: 'teams')]
    #[ORM\JoinColumn(name: 'participating_dojo_id', nullable: false, onDelete: 'CASCADE')]
    private ?ParticipatingDojo $participatingDojo = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $shortname = null;

    /** Ordre de passage issu du tirage au sort. */
    #[ORM\Column(name: '`index`', nullable: true)]
    private ?int $index = null;

    /**
     * Équipe « mixte » : composée d'archers sans équipe de club.
     * Ces équipes sont exclues de la phase finale du taikai.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $mixed = false;

    #[ORM\Column(name: 'intermediate_rank', nullable: true)]
    private ?int $intermediateRank = null;

    #[ORM\Column(nullable: true)]
    private ?int $rank = null;

    /** @var Collection<int, Participant> */
    #[ORM\OneToMany(targetEntity: Participant::class, mappedBy: 'team')]
    #[ORM\OrderBy(['indexInTeam' => 'ASC', 'lastname' => 'ASC', 'firstname' => 'ASC'])]
    private Collection $participants;

    /** @var Collection<int, Score> */
    #[ORM\OneToMany(targetEntity: Score::class, mappedBy: 'team', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $scores;

    public function __construct()
    {
        $this->participants = new ArrayCollection();
        $this->scores = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParticipatingDojo(): ?ParticipatingDojo
    {
        return $this->participatingDojo;
    }

    public function setParticipatingDojo(ParticipatingDojo $participatingDojo): static
    {
        $this->participatingDojo = $participatingDojo;

        return $this;
    }

    public function getTaikai(): ?Taikai
    {
        return $this->participatingDojo?->getTaikai();
    }

    public function getShortname(): ?string
    {
        return $this->shortname;
    }

    public function setShortname(?string $shortname): static
    {
        $this->shortname = $shortname;

        return $this;
    }

    public function getDisplayName(): string
    {
        return (string) $this->shortname;
    }

    public function getIndex(): ?int
    {
        return $this->index;
    }

    public function setIndex(?int $index): static
    {
        $this->index = $index;

        return $this;
    }

    public function isMixed(): bool
    {
        return $this->mixed;
    }

    public function setMixed(bool $mixed): static
    {
        $this->mixed = $mixed;

        return $this;
    }

    public function getIntermediateRank(): ?int
    {
        return $this->intermediateRank;
    }

    public function setIntermediateRank(?int $intermediateRank): static
    {
        $this->intermediateRank = $intermediateRank;

        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): static
    {
        $this->rank = $rank;

        return $this;
    }

    /** @return Collection<int, Participant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(Participant $participant): static
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
            $participant->setTeam($this);
        }

        return $this;
    }

    public function removeParticipant(Participant $participant): static
    {
        if ($this->participants->removeElement($participant)) {
            $participant->setTeam(null);
        }

        return $this;
    }

    /** @return Collection<int, Score> */
    public function getScores(): Collection
    {
        return $this->scores;
    }

    public function addScore(Score $score): static
    {
        if (!$this->scores->contains($score)) {
            $this->scores->add($score);
            $score->setTeam($this);
        }

        return $this;
    }

    public function getScore(?TaikaiMatch $match = null): ?Score
    {
        foreach ($this->scores as $score) {
            if ($score->getMatch() === $match) {
                return $score;
            }
        }

        return null;
    }

    public function __toString(): string
    {
        return (string) $this->shortname;
    }
}

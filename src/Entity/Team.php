<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une équipe, rattachée à un club hôte.
 */
#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'teams')]
#[UniqueConstraint(name: 'teams_by_participating_dojo_index', columns: ['participating_dojo_id', 'index'])]
#[UniqueConstraint(name: 'by_teams_shortname', columns: ['participating_dojo_id', 'shortname'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['participatingDojo', 'index'], message: 'team.index.already_used', errorPath: 'index', ignoreNull: true)]
#[Gedmo\Loggable(logEntryClass: LogEntry::class)]
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
    #[Gedmo\Versioned]
    private ?string $shortname = null;

    /** Ordre de passage issu du tirage au sort. */
    #[ORM\Column(name: '`index`', nullable: true)]
    #[Gedmo\Versioned]
    private ?int $index = null;

    /**
     * Équipe « mixte » : composée d'archers sans équipe de club.
     * Ces équipes sont exclues de la phase finale du taikai.
     */
    #[ORM\Column(options: ['default' => false])]
    #[Gedmo\Versioned]
    private bool $mixed = false;

    #[ORM\Column(name: 'intermediate_rank', nullable: true)]
    #[Gedmo\Versioned]
    private ?int $intermediateRank = null;

    #[ORM\Column(nullable: true)]
    #[Gedmo\Versioned]
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

    /**
     * Rails compare les noms d'équipe insensiblement à la casse
     * (`case_sensitive: false`) au sein d'un même club hôte ; l'index unique
     * en base reste sensible à la casse et ne sert que de filet de sécurité.
     */
    #[Assert\Callback]
    public function validateShortnameIsUnique(ExecutionContextInterface $context): void
    {
        if (null === $this->shortname || null === $this->participatingDojo) {
            return;
        }

        foreach ($this->participatingDojo->getTeams() as $other) {
            if ($other !== $this && null !== $other->shortname && mb_strtolower($other->shortname) === mb_strtolower($this->shortname)) {
                $context->buildViolation('team.shortname.already_used')
                    ->atPath('shortname')
                    ->addViolation();

                return;
            }
        }
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

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ParticipantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un archer inscrit à un taikai, rattaché à un club hôte et éventuellement à une équipe.
 */
#[ORM\Entity(repositoryClass: ParticipantRepository::class)]
#[ORM\Table(name: 'participants')]
#[UniqueConstraint(name: 'participants_by_participating_dojo_index', columns: ['participating_dojo_id', 'index'])]
#[UniqueConstraint(name: 'by_participants_participating_dojo_kyudojin', columns: ['participating_dojo_id', 'kyudojin_id'])]
#[UniqueConstraint(name: 'teams_by_team_index_in_team', columns: ['team_id', 'index_in_team'])]
#[ORM\HasLifecycleCallbacks]
// `allow_blank` côté Rails : plusieurs participants sans licencié ni ordre de
// passage coexistent, PostgreSQL traitant les NULL comme distincts.
#[UniqueEntity(fields: ['participatingDojo', 'kyudojin'], message: 'participant.kyudojin.already_registered', errorPath: 'kyudojin', ignoreNull: true)]
#[UniqueEntity(fields: ['participatingDojo', 'index'], message: 'participant.index.already_used', errorPath: 'index', ignoreNull: true)]
class Participant implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ParticipatingDojo::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'participating_dojo_id', nullable: false, onDelete: 'CASCADE')]
    private ?ParticipatingDojo $participatingDojo = null;

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'team_id', nullable: true, onDelete: 'SET NULL')]
    private ?Team $team = null;

    #[ORM\ManyToOne(targetEntity: Kyudojin::class)]
    #[ORM\JoinColumn(name: 'kyudojin_id', nullable: true)]
    private ?Kyudojin $kyudojin = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    private ?string $lastname = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    private string $club = '';

    /** Ordre de passage issu du tirage au sort, au sein du club hôte. */
    #[ORM\Column(name: '`index`', nullable: true)]
    private ?int $index = null;

    /** Ordre de passage au sein de l'équipe. */
    #[ORM\Column(name: 'index_in_team', nullable: true)]
    private ?int $indexInTeam = null;

    /** Rang calculé à l'entrée en tie-break. */
    #[ORM\Column(name: 'intermediate_rank', nullable: true)]
    private ?int $intermediateRank = null;

    /** Rang final, ajustable manuellement pendant le tie-break. */
    #[ORM\Column(nullable: true)]
    private ?int $rank = null;

    /** @var Collection<int, Score> */
    #[ORM\OneToMany(targetEntity: Score::class, mappedBy: 'participant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $scores;

    public function __construct()
    {
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

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;

        return $this;
    }

    public function getKyudojin(): ?Kyudojin
    {
        return $this->kyudojin;
    }

    public function setKyudojin(?Kyudojin $kyudojin): static
    {
        $this->kyudojin = $kyudojin;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getClub(): string
    {
        return $this->club;
    }

    public function setClub(string $club): static
    {
        $this->club = $club;

        return $this;
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

    public function getIndexInTeam(): ?int
    {
        return $this->indexInTeam;
    }

    public function setIndexInTeam(?int $indexInTeam): static
    {
        $this->indexInTeam = $indexInTeam;

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

    /** @return Collection<int, Score> */
    public function getScores(): Collection
    {
        return $this->scores;
    }

    public function addScore(Score $score): static
    {
        if (!$this->scores->contains($score)) {
            $this->scores->add($score);
            $score->setParticipant($this);
        }

        return $this;
    }

    /**
     * Score du participant pour un match donné (null hors tournoi à matchs).
     */
    public function getScore(?TaikaiMatch $match = null): ?Score
    {
        foreach ($this->scores as $score) {
            if ($score->getMatch() === $match) {
                return $score;
            }
        }

        return null;
    }

    public function getDisplayName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    /** Toutes les flèches du participant sont-elles validées ? */
    public function isFinalized(): bool
    {
        foreach ($this->scores as $score) {
            if (!$score->isFinalized()) {
                return false;
            }
        }

        return true;
    }

    /** Le participant a-t-il au moins une flèche déjà marquée ? */
    public function hasDefinedResults(?TaikaiMatch $match = null): bool
    {
        $score = $this->getScore($match);
        if (null === $score) {
            return false;
        }

        foreach ($score->getResults() as $result) {
            if (null !== $result->getStatus()) {
                return true;
            }
        }

        return false;
    }

    public function isMarking(?TaikaiMatch $match = null): bool
    {
        return $this->getScore($match)?->isMarking() ?? false;
    }

    public function __toString(): string
    {
        return $this->getDisplayName();
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaikaiForm;
use App\Repository\ParticipatingDojoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * Un club hôte : la participation d'un dojo à un taikai donné.
 */
#[ORM\Entity(repositoryClass: ParticipatingDojoRepository::class)]
#[ORM\Table(name: 'participating_dojos')]
#[UniqueConstraint(name: 'by_taikai_dojo', columns: ['taikai_id', 'dojo_id'])]
#[ORM\HasLifecycleCallbacks]
class ParticipatingDojo implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Taikai::class, inversedBy: 'participatingDojos')]
    #[ORM\JoinColumn(name: 'taikai_id', nullable: false, onDelete: 'CASCADE')]
    private ?Taikai $taikai = null;

    #[ORM\ManyToOne(targetEntity: Dojo::class)]
    #[ORM\JoinColumn(name: 'dojo_id', nullable: false)]
    private ?Dojo $dojo = null;

    #[ORM\Column(name: 'display_name', length: 255, nullable: true)]
    private ?string $displayName = null;

    /** @var Collection<int, Participant> */
    #[ORM\OneToMany(targetEntity: Participant::class, mappedBy: 'participatingDojo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['index' => 'ASC', 'lastname' => 'ASC', 'firstname' => 'ASC'])]
    private Collection $participants;

    /** @var Collection<int, Team> */
    #[ORM\OneToMany(targetEntity: Team::class, mappedBy: 'participatingDojo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['index' => 'ASC', 'shortname' => 'ASC'])]
    private Collection $teams;

    /** @var Collection<int, Tachi> */
    #[ORM\OneToMany(targetEntity: Tachi::class, mappedBy: 'participatingDojo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['round' => 'ASC', 'index' => 'ASC'])]
    private Collection $tachis;

    /** @var Collection<int, Staff> */
    #[ORM\OneToMany(targetEntity: Staff::class, mappedBy: 'participatingDojo')]
    private Collection $staffs;

    public function __construct()
    {
        $this->participants = new ArrayCollection();
        $this->teams = new ArrayCollection();
        $this->tachis = new ArrayCollection();
        $this->staffs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaikai(): ?Taikai
    {
        return $this->taikai;
    }

    public function setTaikai(Taikai $taikai): static
    {
        $this->taikai = $taikai;

        return $this;
    }

    public function getDojo(): ?Dojo
    {
        return $this->dojo;
    }

    public function setDojo(Dojo $dojo): static
    {
        $this->dojo = $dojo;
        $this->displayName ??= $dojo->getShortname();

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName ?? $this->dojo?->getShortname();
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

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
            $participant->setParticipatingDojo($this);
        }

        return $this;
    }

    public function removeParticipant(Participant $participant): static
    {
        $this->participants->removeElement($participant);

        return $this;
    }

    /**
     * Participants non affectés à une équipe.
     *
     * @return list<Participant>
     */
    public function getUnteamedParticipants(): array
    {
        return array_values(
            $this->participants
                ->filter(static fn (Participant $p): bool => null === $p->getTeam())
                ->toArray()
        );
    }

    /**
     * Participants triés par ordre de tirage au sort.
     *
     * @return list<Participant>
     */
    public function getDrawOrderedParticipants(): array
    {
        $participants = array_values($this->participants->toArray());
        usort(
            $participants,
            static fn (Participant $a, Participant $b): int => ($a->getIndex() ?? \PHP_INT_MAX) <=> ($b->getIndex() ?? \PHP_INT_MAX)
        );

        return $participants;
    }

    /**
     * Groupes de tir (tachi), par paquets de `num_targets` participants
     * dans l'ordre du tirage au sort.
     *
     * @return list<list<Participant>>
     */
    public function getTachiGroups(): array
    {
        $numTargets = $this->taikai?->getNumTargets() ?? 1;

        return array_chunk($this->getDrawOrderedParticipants(), max(1, $numTargets));
    }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
            $team->setParticipatingDojo($this);
        }

        return $this;
    }

    public function removeTeam(Team $team): static
    {
        $this->teams->removeElement($team);

        return $this;
    }

    /** @return Collection<int, Tachi> */
    public function getTachis(): Collection
    {
        return $this->tachis;
    }

    public function addTachi(Tachi $tachi): static
    {
        if (!$this->tachis->contains($tachi)) {
            $this->tachis->add($tachi);
            $tachi->setParticipatingDojo($this);
        }

        return $this;
    }

    public function getCurrentTachi(): ?Tachi
    {
        foreach ($this->tachis as $tachi) {
            if (!$tachi->isFinished()) {
                return $tachi;
            }
        }

        return null;
    }

    public function getPreviousTachi(): ?Tachi
    {
        $previous = null;
        foreach ($this->tachis as $tachi) {
            if ($tachi->isFinished()) {
                $previous = $tachi;
            }
        }

        return $previous;
    }

    /** @return Collection<int, Staff> */
    public function getStaffs(): Collection
    {
        return $this->staffs;
    }

    /**
     * Le tirage au sort a-t-il été effectué ?
     * En individuel on regarde les participants, en équipes les équipes,
     * et un tournoi à matchs n'a pas de tirage.
     */
    public function isDrawn(): bool
    {
        return match ($this->taikai?->getForm()) {
            TaikaiForm::Individual => $this->participants->forAll(
                static fn (int $_, Participant $p): bool => null !== $p->getIndex()
            ),
            TaikaiForm::Team, TaikaiForm::TwoInOne => $this->teams->forAll(
                static fn (int $_, Team $t): bool => null !== $t->getIndex()
            ),
            TaikaiForm::Matches => true,
            default => false,
        };
    }

    /** Toutes les flèches de tous les participants sont-elles validées ? */
    public function isFinalized(): bool
    {
        foreach ($this->participants as $participant) {
            if (!$participant->isFinalized()) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return (string) $this->getDisplayName();
    }
}

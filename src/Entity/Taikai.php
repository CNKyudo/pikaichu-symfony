<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Repository\TaikaiRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un taikai (tournoi de kyudo). Entité centrale de l'application.
 *
 * L'état courant est dérivé des transitions (`taikai_transitions`), à l'identique
 * du fonctionnement de Statesman côté Rails : la transition portant `most_recent`
 * fait foi, et l'absence de transition signifie l'état initial `new`.
 */
#[ORM\Entity(repositoryClass: TaikaiRepository::class)]
#[ORM\Table(name: 'taikais')]
#[ORM\UniqueConstraint(name: 'by_taikais_shortname', columns: ['shortname'])]
#[ORM\Index(name: 'taikais_by_form', columns: ['form'])]
#[ORM\Index(name: 'taikais_by_scoring', columns: ['scoring'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['shortname'], message: 'taikai.shortname.already_used')]
#[Gedmo\Loggable(logEntryClass: LogEntry::class)]
class Taikai implements \Stringable
{
    use TimestampableTrait;

    /** Nombre de flèches par série (volée). Constante métier, `num_arrows` côté Rails. */
    public const int ARROWS_PER_ROUND = 4;

    public const array CATEGORY_VALUES = ['A', 'B', 'C', 'D'];

    /** Nombre total de flèches autorisé en kinteki (hors tournoi à matchs). */
    public const array KINTEKI_TOTAL_ARROWS = [8, 12, 20];

    public const array TACHI_SIZES = [3, 5];

    public const array NUM_TARGETS = [3, 5, 6, 9, 10];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 32)]
    #[Assert\Regex(
        pattern: '/\A(?![0-9]+$)(?!-)[a-zA-Z0-9-]{1,63}(?<!-)\z/',
        message: 'taikai.shortname.invalid_format'
    )]
    #[Gedmo\Versioned]
    private ?string $shortname = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    #[Gedmo\Versioned]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Gedmo\Versioned]
    private ?string $description = null;

    #[ORM\Column(name: 'start_date', type: 'date_immutable', nullable: true)]
    #[Assert\NotNull]
    #[Gedmo\Versioned]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable', nullable: true)]
    #[Assert\NotNull]
    #[Gedmo\Versioned]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: TaikaiForm::class)]
    #[Assert\NotNull]
    #[Gedmo\Versioned]
    private ?TaikaiForm $form = null;

    #[ORM\Column(type: 'string', options: ['default' => 'kinteki'], enumType: TaikaiScoring::class)]
    #[Gedmo\Versioned]
    private TaikaiScoring $scoring = TaikaiScoring::Kinteki;

    #[ORM\Column(name: 'total_num_arrows', type: 'smallint', options: ['default' => 12])]
    #[Assert\NotNull]
    #[Gedmo\Versioned]
    private int $totalNumArrows = 12;

    #[ORM\Column(name: 'num_targets', type: 'smallint', options: ['default' => 6])]
    #[Assert\NotNull]
    #[Assert\Choice(choices: self::NUM_TARGETS)]
    #[Gedmo\Versioned]
    private int $numTargets = 6;

    #[ORM\Column(name: 'tachi_size', type: 'smallint', options: ['default' => 3])]
    #[Assert\NotNull]
    #[Assert\Choice(choices: self::TACHI_SIZES)]
    #[Gedmo\Versioned]
    private int $tachiSize = 3;

    /** Tournoi « à distance » : plusieurs clubs hôtes autorisés. */
    #[ORM\Column(options: ['default' => true])]
    #[Gedmo\Versioned]
    private bool $distributed = true;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Choice(choices: self::CATEGORY_VALUES)]
    #[Gedmo\Versioned]
    private ?string $category = null;

    /** @var Collection<int, ParticipatingDojo> */
    #[ORM\OneToMany(targetEntity: ParticipatingDojo::class, mappedBy: 'taikai', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayName' => 'ASC'])]
    private Collection $participatingDojos;

    /** @var Collection<int, Staff> */
    #[ORM\OneToMany(targetEntity: Staff::class, mappedBy: 'taikai', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $staffs;

    /** @var Collection<int, TaikaiMatch> */
    #[ORM\OneToMany(targetEntity: TaikaiMatch::class, mappedBy: 'taikai', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $matches;

    /** @var Collection<int, TaikaiTransition> */
    #[ORM\OneToMany(targetEntity: TaikaiTransition::class, mappedBy: 'taikai', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortKey' => 'ASC'])]
    private Collection $transitions;

    /** @var Collection<int, TaikaiEvent> */
    #[ORM\OneToMany(targetEntity: TaikaiEvent::class, mappedBy: 'taikai', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $events;

    public function __construct()
    {
        $this->participatingDojos = new ArrayCollection();
        $this->staffs = new ArrayCollection();
        $this->matches = new ArrayCollection();
        $this->transitions = new ArrayCollection();
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getForm(): ?TaikaiForm
    {
        return $this->form;
    }

    public function setForm(?TaikaiForm $form): static
    {
        $this->form = $form;

        return $this;
    }

    public function isForm(TaikaiForm ...$forms): bool
    {
        return \in_array($this->form, $forms, true);
    }

    public function getScoring(): TaikaiScoring
    {
        return $this->scoring;
    }

    public function setScoring(TaikaiScoring $scoring): static
    {
        $this->scoring = $scoring;

        return $this;
    }

    public function getTotalNumArrows(): int
    {
        return $this->totalNumArrows;
    }

    public function setTotalNumArrows(int $totalNumArrows): static
    {
        $this->totalNumArrows = $totalNumArrows;

        return $this;
    }

    /** Nombre de flèches par série. */
    public function getNumArrows(): int
    {
        return self::ARROWS_PER_ROUND;
    }

    /** Nombre de séries (volées) du taikai. */
    public function getNumRounds(): int
    {
        return intdiv($this->totalNumArrows, self::ARROWS_PER_ROUND);
    }

    public function getNumTargets(): int
    {
        return $this->numTargets;
    }

    public function setNumTargets(int $numTargets): static
    {
        $this->numTargets = $numTargets;

        return $this;
    }

    public function getTachiSize(): int
    {
        return $this->tachiSize;
    }

    public function setTachiSize(int $tachiSize): static
    {
        $this->tachiSize = $tachiSize;

        return $this;
    }

    public function isDistributed(): bool
    {
        return $this->distributed;
    }

    public function setDistributed(bool $distributed): static
    {
        $this->distributed = $distributed;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    /** @return Collection<int, ParticipatingDojo> */
    public function getParticipatingDojos(): Collection
    {
        return $this->participatingDojos;
    }

    public function addParticipatingDojo(ParticipatingDojo $participatingDojo): static
    {
        if (!$this->participatingDojos->contains($participatingDojo)) {
            $this->participatingDojos->add($participatingDojo);
            $participatingDojo->setTaikai($this);
        }

        return $this;
    }

    public function removeParticipatingDojo(ParticipatingDojo $participatingDojo): static
    {
        $this->participatingDojos->removeElement($participatingDojo);

        return $this;
    }

    /** @return Collection<int, Staff> */
    public function getStaffs(): Collection
    {
        return $this->staffs;
    }

    public function addStaff(Staff $staff): static
    {
        if (!$this->staffs->contains($staff)) {
            $this->staffs->add($staff);
            $staff->setTaikai($this);
        }

        return $this;
    }

    public function removeStaff(Staff $staff): static
    {
        $this->staffs->removeElement($staff);

        return $this;
    }

    /**
     * Membres du staff portant l'un des rôles demandés.
     *
     * @return Collection<int, Staff>
     */
    public function getStaffsWithRole(\App\Enum\StaffRoleCode ...$codes): Collection
    {
        return $this->staffs->filter(
            static fn (Staff $staff): bool => \in_array($staff->getRole()?->getCode(), $codes, true)
        );
    }

    /** Vrai si l'utilisateur tient l'un des rôles indiqués sur ce taikai. */
    public function hasRole(User $user, \App\Enum\StaffRoleCode ...$codes): bool
    {
        foreach ($this->staffs as $staff) {
            if ($staff->getUser() === $user && \in_array($staff->getRole()?->getCode(), $codes, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, TaikaiMatch> */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    public function addMatch(TaikaiMatch $match): static
    {
        if (!$this->matches->contains($match)) {
            $this->matches->add($match);
            $match->setTaikai($this);
        }

        return $this;
    }

    /**
     * Matchs d'un niveau donné, triés par index.
     *
     * @return list<TaikaiMatch>
     */
    public function getMatchesAtLevel(int $level): array
    {
        $matches = array_values(
            $this->matches
                ->matching(Criteria::create()->where(Criteria::expr()->eq('level', $level)))
                ->toArray()
        );

        usort($matches, static fn (TaikaiMatch $a, TaikaiMatch $b): int => $a->getIndex() <=> $b->getIndex());

        return $matches;
    }

    /** @return Collection<int, TaikaiTransition> */
    public function getTransitions(): Collection
    {
        return $this->transitions;
    }

    public function addTransition(TaikaiTransition $transition): static
    {
        if (!$this->transitions->contains($transition)) {
            $this->transitions->add($transition);
            $transition->setTaikai($this);
        }

        return $this;
    }

    /** @return Collection<int, TaikaiEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(TaikaiEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setTaikai($this);
        }

        return $this;
    }

    /**
     * État courant, dérivé de la transition la plus récente.
     * Sans transition enregistrée, le taikai est dans l'état initial `new`.
     */
    public function getCurrentState(): TaikaiState
    {
        foreach ($this->transitions as $transition) {
            if ($transition->isMostRecent()) {
                return $transition->getToState();
            }
        }

        return TaikaiState::New;
    }

    public function isState(TaikaiState ...$states): bool
    {
        return \in_array($this->getCurrentState(), $states, true);
    }

    public function getPreviousState(): ?TaikaiState
    {
        return $this->getCurrentState()->previous();
    }

    public function getNextState(): ?TaikaiState
    {
        return $this->getCurrentState()->next();
    }

    /**
     * Tous les participants du taikai, tous clubs hôtes confondus.
     *
     * @return list<Participant>
     */
    public function getParticipants(): array
    {
        $participants = [];
        foreach ($this->participatingDojos as $participatingDojo) {
            foreach ($participatingDojo->getParticipants() as $participant) {
                $participants[] = $participant;
            }
        }

        return $participants;
    }

    /**
     * Toutes les équipes du taikai, tous clubs hôtes confondus.
     *
     * @return list<Team>
     */
    public function getTeams(): array
    {
        $teams = [];
        foreach ($this->participatingDojos as $participatingDojo) {
            foreach ($participatingDojo->getTeams() as $team) {
                $teams[] = $team;
            }
        }

        return $teams;
    }

    /** Vrai si toutes les flèches de tous les participants sont validées. */
    public function isFinalized(): bool
    {
        foreach ($this->participatingDojos as $participatingDojo) {
            if (!$participatingDojo->isFinalized()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Un taikai non distribué (« sur place ») ne peut avoir qu'un seul club hôte.
     * Reprend la validation `number_of_dojos` du modèle Rails.
     */
    #[Assert\Callback]
    public function validateNumberOfDojos(ExecutionContextInterface $context): void
    {
        if (!$this->distributed && $this->participatingDojos->count() > 1) {
            $context->buildViolation('taikai.distributed.num_participating_dojos')
                ->atPath('distributed')
                ->addViolation();
        }
    }

    /**
     * Nombre total de flèches : 4 en tournoi à matchs, sinon 8/12/20 en kinteki.
     * Reprend les validations conditionnelles `total_num_arrows` du modèle Rails.
     */
    #[Assert\Callback]
    public function validateTotalNumArrows(ExecutionContextInterface $context): void
    {
        if (TaikaiForm::Matches === $this->form) {
            if (4 !== $this->totalNumArrows) {
                $context->buildViolation('taikai.total_num_arrows.must_be_four_for_matches')
                    ->atPath('totalNumArrows')
                    ->addViolation();
            }

            return;
        }

        if (TaikaiScoring::Kinteki === $this->scoring
            && !\in_array($this->totalNumArrows, self::KINTEKI_TOTAL_ARROWS, true)) {
            $context->buildViolation('taikai.total_num_arrows.invalid_for_kinteki')
                ->atPath('totalNumArrows')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return (string) $this->shortname;
    }
}

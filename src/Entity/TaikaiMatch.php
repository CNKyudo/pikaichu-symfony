<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaikaiMatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une rencontre entre deux équipes de la phase finale d'un tournoi « à matchs ».
 *
 * Nommée `TaikaiMatch` car `Match` est un mot réservé depuis PHP 8 ; la table
 * reste `matches` comme côté Rails.
 *
 * Les niveaux suivent la convention de l'application Rails :
 *  - 3 : quarts de finale (bracket de 8 équipes) ;
 *  - 2 : demi-finales ;
 *  - 1 : finales, index 1 pour la grande finale et index 2 pour la petite finale.
 */
#[ORM\Entity(repositoryClass: TaikaiMatchRepository::class)]
#[ORM\Table(name: 'matches')]
#[ORM\HasLifecycleCallbacks]
class TaikaiMatch implements \Stringable
{
    use TimestampableTrait;

    public const int LEVEL_FINAL = 1;

    public const int LEVEL_SEMI_FINAL = 2;

    public const int LEVEL_QUARTER_FINAL = 3;

    /** Index de la grande finale au niveau 1. */
    public const int INDEX_GRAND_FINAL = 1;

    /** Index de la petite finale (3e place) au niveau 1. */
    public const int INDEX_SMALL_FINAL = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Taikai::class, inversedBy: 'matches')]
    #[ORM\JoinColumn(name: 'taikai_id', nullable: false, onDelete: 'CASCADE')]
    private ?Taikai $taikai = null;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team1_id', nullable: true, onDelete: 'SET NULL')]
    private ?Team $team1 = null;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team2_id', nullable: true, onDelete: 'SET NULL')]
    private ?Team $team2 = null;

    #[ORM\Column(name: '`index`', type: 'smallint')]
    private int $index = 1;

    #[ORM\Column(type: 'smallint')]
    private int $level = self::LEVEL_FINAL;

    /** Numéro de l'équipe gagnante (1 ou 2), null tant que le match n'est pas tranché. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $winner = null;

    /** @var Collection<int, Result> */
    #[ORM\OneToMany(targetEntity: Result::class, mappedBy: 'match')]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
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

    public function getTeam1(): ?Team
    {
        return $this->team1;
    }

    public function setTeam1(?Team $team1): static
    {
        $this->team1 = $team1;

        return $this;
    }

    public function getTeam2(): ?Team
    {
        return $this->team2;
    }

    public function setTeam2(?Team $team2): static
    {
        $this->team2 = $team2;

        return $this;
    }

    public function getTeam(int $index): ?Team
    {
        return match ($index) {
            1 => $this->team1,
            2 => $this->team2,
            default => throw new \InvalidArgumentException('index must be 1 or 2'),
        };
    }

    public function setTeam(int $index, ?Team $team): static
    {
        match ($index) {
            1 => $this->team1 = $team,
            2 => $this->team2 = $team,
            default => throw new \InvalidArgumentException('index must be 1 or 2'),
        };

        return $this;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function setIndex(int $index): static
    {
        $this->index = $index;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getWinner(): ?int
    {
        return $this->winner;
    }

    public function setWinner(?int $winner): static
    {
        if (null !== $winner && 1 !== $winner && 2 !== $winner) {
            throw new \InvalidArgumentException('Winner can be only 1 or 2');
        }

        $this->winner = $winner;

        return $this;
    }

    public function isWinner(int $index): bool
    {
        return $this->winner === $index;
    }

    public function getWinningTeam(): ?Team
    {
        return null === $this->winner ? null : $this->getTeam($this->winner);
    }

    /** @return Collection<int, Result> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function getScore(int $index): ?Score
    {
        return $this->getTeam($index)?->getScore($this);
    }

    /** Les deux équipes sont-elles connues ? */
    public function isAssigned(): bool
    {
        return null !== $this->team1 && null !== $this->team2;
    }

    public function isDecided(): bool
    {
        return null !== $this->winner;
    }

    /** Toutes les flèches du match sont-elles validées ? */
    public function isFinalized(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->isFinal()) {
                return false;
            }
        }

        return true;
    }

    /** Au moins une flèche a-t-elle déjà été saisie sur ce match ? */
    public function hasDefinedResults(): bool
    {
        foreach ($this->results as $result) {
            if (null !== $result->getStatus()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Équipes classées gagnante puis perdante.
     *
     * @return list<Team|null>
     */
    public function getOrderedTeams(): array
    {
        return $this->isWinner(1)
            ? [$this->team1, $this->team2]
            : [$this->team2, $this->team1];
    }

    public function __toString(): string
    {
        return \sprintf(
            'Match %d.%d (%s vs %s)',
            $this->level,
            $this->index,
            $this->team1?->getShortname() ?? '-',
            $this->team2?->getShortname() ?? '-',
        );
    }
}

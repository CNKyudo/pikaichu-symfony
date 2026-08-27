<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaikaiState;
use App\Repository\TaikaiTransitionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une transition d'état du taikai. Reprend la table `taikai_transitions` gérée par
 * Statesman côté Rails : `sort_key` ordonne l'historique et `most_recent` (unique
 * partiel) désigne la transition qui porte l'état courant.
 */
#[ORM\Entity(repositoryClass: TaikaiTransitionRepository::class)]
#[ORM\Table(name: 'taikai_transitions')]
#[ORM\UniqueConstraint(name: 'index_taikai_transitions_parent_sort', columns: ['taikai_id', 'sort_key'])]
// Index unique partiel : une seule transition `most_recent` par taikai. Déclaré ici
// pour que `doctrine:schema:validate` le reconnaisse, DBAL 4 introspectant la clause
// `WHERE` des index PostgreSQL.
#[ORM\UniqueConstraint(
    name: 'index_taikai_transitions_parent_most_recent',
    columns: ['taikai_id', 'most_recent'],
    options: ['where' => 'most_recent'],
)]
#[ORM\HasLifecycleCallbacks]
class TaikaiTransition
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Taikai::class, inversedBy: 'transitions')]
    #[ORM\JoinColumn(name: 'taikai_id', nullable: false, onDelete: 'CASCADE')]
    private ?Taikai $taikai = null;

    #[ORM\Column(name: 'to_state', length: 255, enumType: TaikaiState::class)]
    private TaikaiState $toState = TaikaiState::New;

    #[ORM\Column(name: 'sort_key')]
    private int $sortKey = 0;

    /**
     * Vrai pour la seule transition courante du taikai. Un index unique partiel
     * (`WHERE most_recent`) garantit l'unicité côté base.
     */
    #[ORM\Column(name: 'most_recent')]
    private bool $mostRecent = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $metadata = [];

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

    public function getToState(): TaikaiState
    {
        return $this->toState;
    }

    public function setToState(TaikaiState $toState): static
    {
        $this->toState = $toState;

        return $this;
    }

    public function getSortKey(): int
    {
        return $this->sortKey;
    }

    public function setSortKey(int $sortKey): static
    {
        $this->sortKey = $sortKey;

        return $this;
    }

    public function isMostRecent(): bool
    {
        return $this->mostRecent;
    }

    public function setMostRecent(bool $mostRecent): static
    {
        $this->mostRecent = $mostRecent;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }
}

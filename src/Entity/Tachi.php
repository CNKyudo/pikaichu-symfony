<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TachiRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * Un tachi : le groupe d'archers qui tire ensemble sur les cibles disponibles,
 * pour une série donnée. Sert à suivre l'avancement du tir sur le shajo.
 */
#[ORM\Entity(repositoryClass: TachiRepository::class)]
#[ORM\Table(name: 'tachis')]
#[UniqueConstraint(name: 'index_tachis_on_participating_dojo_id_and_index_and_round', columns: ['participating_dojo_id', 'index', 'round'])]
#[ORM\HasLifecycleCallbacks]
class Tachi
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ParticipatingDojo::class, inversedBy: 'tachis')]
    #[ORM\JoinColumn(name: 'participating_dojo_id', nullable: false, onDelete: 'CASCADE')]
    private ?ParticipatingDojo $participatingDojo = null;

    #[ORM\ManyToOne(targetEntity: TaikaiMatch::class)]
    #[ORM\JoinColumn(name: 'match_id', nullable: true, onDelete: 'CASCADE')]
    private ?TaikaiMatch $match = null;

    #[ORM\Column]
    private int $round = 1;

    #[ORM\Column(name: '`index`')]
    private int $index = 1;

    #[ORM\Column(options: ['default' => false])]
    private bool $finished = false;

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

    public function getMatch(): ?TaikaiMatch
    {
        return $this->match;
    }

    public function setMatch(?TaikaiMatch $match): static
    {
        $this->match = $match;

        return $this;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function setRound(int $round): static
    {
        $this->round = $round;

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

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function setFinished(bool $finished): static
    {
        $this->finished = $finished;

        return $this;
    }

    /**
     * Archers de ce tachi : le groupe de tir correspondant hors tournoi à matchs,
     * les deux équipes qui s'affrontent sinon.
     *
     * @return list<Participant>
     */
    public function getParticipants(): array
    {
        if (null !== $this->match) {
            return [
                ...($this->match->getTeam1()?->getParticipants()->toArray() ?? []),
                ...($this->match->getTeam2()?->getParticipants()->toArray() ?? []),
            ];
        }

        return $this->participatingDojo?->getTachiGroups()[$this->index - 1] ?? [];
    }
}

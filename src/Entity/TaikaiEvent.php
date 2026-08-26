<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaikaiState;
use App\Repository\TaikaiEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des évènements d'un taikai, affiché dans la frise d'avancement.
 * Reprend la table `taikai_events` de l'application Rails.
 */
#[ORM\Entity(repositoryClass: TaikaiEventRepository::class)]
#[ORM\Table(name: 'taikai_events')]
#[ORM\HasLifecycleCallbacks]
class TaikaiEvent
{
    public const string CATEGORY_STATE_TRANSITION = 'state_transition';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Taikai::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'taikai_id', nullable: false, onDelete: 'CASCADE')]
    private ?Taikai $taikai = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    /** Fabrique l'évènement enregistré à chaque changement d'état. */
    public static function stateTransition(
        Taikai $taikai,
        User $user,
        TaikaiState $from,
        TaikaiState $to,
    ): self {
        $event = new self();
        $event->taikai = $taikai;
        $event->user = $user;
        $event->category = self::CATEGORY_STATE_TRANSITION;
        $event->data = ['from' => $from->value, 'to' => $to->value];
        $event->message = \sprintf(
            "%s a passé '%s' de l'état '%s' à l'état '%s'.",
            $user->getDisplayName(),
            (string) $taikai->getShortname(),
            $from->value,
            $to->value,
        );

        return $event;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getData(): ?array
    {
        return $this->data;
    }

    /** @param array<string, mixed>|null $data */
    public function setData(?array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ResultStatus;
use App\Enum\TaikaiScoring;
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

    public const string CATEGORY_TIE_BREAK = 'tie_break';

    public const string CATEGORY_RECTIFICATION = 'rectification';

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

    /**
     * Fabrique l'évènement enregistré à chaque changement d'état.
     *
     * @param string $message message déjà traduit (états inclus), construit par
     *                        l'appelant qui a accès au traducteur — reprend le
     *                        principe de Rails, où `I18n.t` produit directement la
     *                        phrase finale au moment de la création de l'évènement
     */
    public static function stateTransition(
        Taikai $taikai,
        User $user,
        TaikaiState $from,
        TaikaiState $to,
        string $message,
    ): self {
        $event = new self();
        $event->taikai = $taikai;
        $event->user = $user;
        $event->category = self::CATEGORY_STATE_TRANSITION;
        $event->data = ['from' => $from->value, 'to' => $to->value];
        $event->message = $message;

        return $event;
    }

    /** Fabrique l'évènement enregistré à chaque ajustement manuel de rang. */
    public static function tieBreak(Taikai $taikai, User $user, Participant|Team $rankable): self
    {
        $event = new self();
        $event->taikai = $taikai;
        $event->user = $user;
        $event->category = self::CATEGORY_TIE_BREAK;
        $event->data = [
            'id' => $rankable->getId(),
            'display_name' => $rankable->getDisplayName(),
            'intermediate_rank' => $rankable->getIntermediateRank(),
            'rank' => $rankable->getRank(),
        ];
        $event->message = \sprintf(
            '%s a classé %s%s au rang %d (rang intermédiaire : %d).',
            $user->getDisplayName(),
            $rankable instanceof Team ? 'l\'équipe ' : '',
            $rankable->getDisplayName(),
            $rankable->getRank() ?? 0,
            $rankable->getIntermediateRank() ?? 0,
        );

        return $event;
    }

    /** Fabrique l'évènement enregistré à chaque rectification d'une flèche. */
    public static function rectification(
        Taikai $taikai,
        User $user,
        Result $result,
        ResultStatus $previousStatus,
        ?int $previousValue,
    ): self {
        $event = new self();
        $event->taikai = $taikai;
        $event->user = $user;
        $event->category = self::CATEGORY_RECTIFICATION;
        $event->data = [
            'id' => $result->getId(),
            'round' => $result->getRound(),
            'index' => $result->getIndex(),
            'status' => $result->getStatus()?->value,
            'previous_status' => $previousStatus->value,
            'value' => $result->getValue(),
            'previous_value' => $previousValue,
        ];
        $participant = $result->getScore()?->getParticipant()?->getDisplayName() ?? '?';
        $from = TaikaiScoring::Enteki === $taikai->getScoring()
            ? (string) ($previousValue ?? 0)
            : $previousStatus->symbol();
        $to = TaikaiScoring::Enteki === $taikai->getScoring()
            ? (string) ($result->getValue() ?? 0)
            : ($result->getStatus()?->symbol() ?? '?');

        $event->message = \sprintf(
            'La flèche %d de la série %d de %s a été rectifiée de « %s » à « %s ».',
            $result->getIndex() ?? 0,
            $result->getRound() ?? 0,
            $participant,
            $from,
            $to,
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

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ResultStatus;
use App\Enum\TaikaiScoring;
use App\Repository\ResultRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une flèche tirée : le plus petit grain de la feuille de marque.
 *
 * `final` marque une flèche validée par le juge de cible ; une fois validée elle
 * n'est plus modifiable, sauf via l'écran de rectification qui pose `overriden`.
 */
#[ORM\Entity(repositoryClass: ResultRepository::class)]
#[ORM\Table(name: 'results')]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable(logEntryClass: LogEntry::class)]
class Result implements \Stringable
{
    use TimestampableTrait;

    /** Valeurs de flèche autorisées en enteki. */
    public const array ENTEKI_VALUES = [0, 3, 5, 7, 9, 10];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Score::class, inversedBy: 'results')]
    #[ORM\JoinColumn(name: 'score_id', nullable: false, onDelete: 'CASCADE')]
    private ?Score $score = null;

    #[ORM\ManyToOne(targetEntity: TaikaiMatch::class, inversedBy: 'results')]
    #[ORM\JoinColumn(name: 'match_id', nullable: true, onDelete: 'CASCADE')]
    private ?TaikaiMatch $match = null;

    /** Numéro de série (volée), à partir de 1. */
    #[ORM\Column(nullable: true)]
    #[Gedmo\Versioned]
    private ?int $round = null;

    /** Numéro de la flèche au sein de la série, à partir de 1. */
    #[ORM\Column(name: '`index`', nullable: true)]
    #[Gedmo\Versioned]
    private ?int $index = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: ResultStatus::class)]
    #[Gedmo\Versioned]
    private ?ResultStatus $status = null;

    /** Points de la flèche, uniquement en enteki. */
    #[ORM\Column(nullable: true)]
    #[Gedmo\Versioned]
    private ?int $value = null;

    #[ORM\Column(options: ['default' => false])]
    #[Gedmo\Versioned]
    private bool $final = false;

    /** Posé par l'écran de rectification, autorise la modification d'une flèche validée. */
    #[ORM\Column(options: ['default' => false])]
    #[Gedmo\Versioned]
    private bool $overriden = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScore(): ?Score
    {
        return $this->score;
    }

    public function setScore(Score $score): static
    {
        $this->score = $score;

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

    public function getTaikai(): ?Taikai
    {
        return $this->score?->getTaikai();
    }

    public function getRound(): ?int
    {
        return $this->round;
    }

    public function setRound(?int $round): static
    {
        $this->round = $round;

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

    public function getStatus(): ?ResultStatus
    {
        return $this->status;
    }

    public function setStatus(?ResultStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    /**
     * En enteki la valeur détermine le statut : 0 point vaut manqué, sinon touché.
     * Reprend le setter `value=` du modèle Rails.
     */
    public function setValue(?int $value): static
    {
        $this->value = $value;
        if (null !== $value) {
            $this->status = 0 === $value ? ResultStatus::Miss : ResultStatus::Hit;
        }

        return $this;
    }

    public function isFinal(): bool
    {
        return $this->final;
    }

    public function setFinal(bool $final): static
    {
        $this->final = $final;

        return $this;
    }

    public function isOverriden(): bool
    {
        return $this->overriden;
    }

    public function setOverriden(bool $overriden): static
    {
        $this->overriden = $overriden;

        return $this;
    }

    public function isHit(): bool
    {
        return ResultStatus::Hit === $this->status;
    }

    public function isMiss(): bool
    {
        return ResultStatus::Miss === $this->status;
    }

    public function isUnknown(): bool
    {
        return ResultStatus::Unknown === $this->status;
    }

    /** La flèche a-t-elle été saisie ? */
    public function isMarked(): bool
    {
        return null !== $this->status;
    }

    public function isEmpty(): bool
    {
        return null === $this->status;
    }

    /** Le statut est-il tranché (ni vide, ni incertain) ? */
    public function isKnown(): bool
    {
        return null !== $this->status && $this->status->isKnown();
    }

    /**
     * Fait tourner le statut au clic sur la feuille de marque.
     *
     * @param bool $allMarked vrai quand toutes les flèches sont marquées, ce qui
     *                        retire « incertain » du cycle
     */
    public function rotateStatus(bool $allMarked): static
    {
        if (null === $this->status) {
            throw new \LogicException('Cannot rotate a result that has not been marked yet');
        }

        $this->status = $this->status->rotate($allMarked);

        return $this;
    }

    /** Fait tourner la valeur enteki sur le cycle 0 → 3 → 5 → 7 → 9 → 10 → 0. */
    public function rotateValue(): static
    {
        $values = self::ENTEKI_VALUES;
        $position = array_search($this->value, $values, true);
        $next = false === $position ? $values[0] : ($values[$position + 1] ?? $values[0]);

        return $this->setValue($next);
    }

    /** Modification depuis l'écran de rectification : trace le passage en force. */
    public function overrideStatus(ResultStatus $status): bool
    {
        if ($this->status === $status) {
            return false;
        }

        $this->status = $status;
        $this->overriden = true;

        return true;
    }

    public function overrideValue(?int $value): bool
    {
        if ($this->value === $value) {
            return false;
        }

        $this->setValue($value);
        $this->overriden = true;

        return true;
    }

    /**
     * En enteki chaque flèche doit porter une valeur valide.
     * Reprend la validation conditionnelle `value` du modèle Rails.
     */
    #[Assert\Callback]
    public function validateEntekiValue(ExecutionContextInterface $context): void
    {
        if (TaikaiScoring::Enteki !== $this->getTaikai()?->getScoring()) {
            return;
        }

        if (null === $this->value || !\in_array($this->value, self::ENTEKI_VALUES, true)) {
            $context->buildViolation('result.value.invalid_for_enteki')
                ->atPath('value')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        $symbol = $this->status?->glyph() ?? ' ';

        return null !== $this->value ? $symbol.'/'.$this->value : $symbol;
    }
}

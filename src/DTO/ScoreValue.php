<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Valeur d'un score, utilisée pour comparer et regrouper les ex æquo.
 *
 * Reprend la classe `Score::ScoreValue` de l'application Rails, y compris son ordre
 * de comparaison : on compare d'abord la valeur (points enteki), puis le nombre de
 * touchés. Deux scores sont ex æquo lorsque les deux composantes sont égales.
 */
final readonly class ScoreValue implements \Stringable
{
    public function __construct(
        public int $hits,
        public ?int $value = null,
    ) {
    }

    /**
     * Ordre croissant. Les classements affichent l'inverse (meilleur score en tête).
     */
    public function compareTo(self $other): int
    {
        return [$this->value ?? 0, $this->hits] <=> [$other->value ?? 0, $other->hits];
    }

    public function equals(self $other): bool
    {
        return $this->hits === $other->hits && $this->value === $other->value;
    }

    /**
     * Clé de regroupement des ex æquo, équivalente au `hash` du modèle Rails
     * (qui normalise une valeur nulle en 0).
     */
    public function groupKey(): string
    {
        return ($this->value ?? 0).':'.$this->hits;
    }

    public function plus(self $other): self
    {
        if (null !== $this->value || null !== $other->value) {
            return new self(
                hits: $this->hits + $other->hits,
                value: ($this->value ?? 0) + ($other->value ?? 0),
            );
        }

        return new self(hits: $this->hits + $other->hits);
    }

    public function __toString(): string
    {
        return \sprintf('Score(hits: %d, value: %s)', $this->hits, $this->value ?? 'null');
    }
}

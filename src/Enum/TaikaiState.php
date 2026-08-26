<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * États du taikai, repris de la machine à états Statesman de l'application Rails
 * (TaikaiStateMachine). L'ordre des cas définit l'ordre de la frise d'avancement.
 */
enum TaikaiState: string
{
    case New = 'new';
    case Registration = 'registration';
    case Marking = 'marking';
    case TieBreak = 'tie_break';
    case Done = 'done';

    public function label(): string
    {
        return 'taikai.state.'.$this->value;
    }

    /** Rang dans la frise, à partir de 1. */
    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function previous(): ?self
    {
        return self::cases()[$this->position() - 2] ?? null;
    }

    public function next(): ?self
    {
        return self::cases()[$this->position()] ?? null;
    }
}

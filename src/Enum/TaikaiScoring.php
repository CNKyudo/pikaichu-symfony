<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type de score. Kinteki = 28 mètres, Enteki = 60 mètres.
 * Correspond au type PostgreSQL `taikai_scoring` de l'application Rails.
 */
enum TaikaiScoring: string
{
    case Kinteki = 'kinteki';
    case Enteki = 'enteki';

    public function label(): string
    {
        return 'taikai.scoring.'.$this->value;
    }

    /** En enteki chaque flèche porte une valeur, en kinteki seul le touché compte. */
    public function usesArrowValues(): bool
    {
        return self::Enteki === $this;
    }
}

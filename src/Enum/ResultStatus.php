<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'une flèche. Correspond au type PostgreSQL `result_status` de l'application Rails.
 */
enum ResultStatus: string
{
    case Hit = 'hit';
    case Miss = 'miss';
    case Unknown = 'unknown';

    /** Symbole affiché sur la feuille de marque. */
    public function symbol(): string
    {
        return match ($this) {
            self::Hit => 'O',
            self::Miss => 'X',
            self::Unknown => '?',
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Hit => '◯',
            self::Miss => '⨯',
            self::Unknown => '?',
        };
    }

    /** Un statut est « connu » dès lors qu'il n'est plus incertain. */
    public function isKnown(): bool
    {
        return self::Unknown !== $this;
    }

    /**
     * Rotation au clic sur la feuille de marque.
     * Reprend Result#rotate_status : hit → miss → (unknown|hit) → hit.
     */
    public function rotate(bool $allMarked): self
    {
        return match ($this) {
            self::Hit => self::Miss,
            self::Miss => $allMarked ? self::Hit : self::Unknown,
            self::Unknown => self::Hit,
        };
    }
}

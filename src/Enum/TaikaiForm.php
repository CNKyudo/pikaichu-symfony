<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Forme du taikai. Correspond au type PostgreSQL `taikai_form` de l'application Rails.
 */
enum TaikaiForm: string
{
    case Individual = 'individual';
    case Team = 'team';
    case TwoInOne = '2in1';
    case Matches = 'matches';

    public function label(): string
    {
        return 'taikai.form.'.$this->value;
    }

    /** Les formes qui reposent sur des équipes. */
    public function isTeamBased(): bool
    {
        return \in_array($this, [self::Team, self::TwoInOne, self::Matches], true);
    }
}

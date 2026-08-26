<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Participant;
use App\Entity\Team;

/**
 * Un groupe d'ex æquo dans un classement : tous les membres partagent le même rang.
 *
 * @template T of Participant|Team
 */
final readonly class RankedGroup
{
    /**
     * @param int             $rank    rang partagé par les membres, à partir de 1
     * @param list<T>         $members membres triés par ordre de tirage au sort
     * @param ScoreValue|null $score   score du groupe, null en tie-break où le rang prime
     */
    public function __construct(
        public int $rank,
        public array $members,
        public ?ScoreValue $score = null,
    ) {
    }

    public function count(): int
    {
        return \count($this->members);
    }

    /** Vrai si plusieurs concurrents se partagent ce rang. */
    public function isTied(): bool
    {
        return \count($this->members) > 1;
    }
}

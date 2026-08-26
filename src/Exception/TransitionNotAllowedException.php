<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\TaikaiState;

/**
 * Levée lorsqu'un changement d'état du taikai est refusé, soit parce que la
 * transition n'existe pas, soit parce qu'une garde n'est pas satisfaite.
 */
final class TransitionNotAllowedException extends \DomainException
{
    /**
     * @param string $reason clé de traduction expliquant le refus
     */
    public function __construct(
        public readonly TaikaiState $from,
        public readonly TaikaiState $to,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf(
            'Transition from "%s" to "%s" is not allowed: %s',
            $from->value,
            $to->value,
            $reason,
        ));
    }
}

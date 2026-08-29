<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levée lorsque la génération de la 2ᵉ partie d'un 2-en-1 ne peut pas aboutir.
 */
final class TaikaiGenerationException extends \DomainException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($translationKey);
    }
}

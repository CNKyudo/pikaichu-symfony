<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Erreurs de saisie sur la feuille de marque.
 */
class MarkingException extends \DomainException
{
    /** Toutes les flèches du participant sont déjà saisies. */
    public static function noEmptyResult(): self
    {
        return new self('Unable to find an undefined result to mark');
    }

    /** On ne peut pas entamer une série tant que la précédente n'est pas validée. */
    public static function previousRoundNotValidated(int $previousRound): self
    {
        return new self(\sprintf('Round %d has not been validated', $previousRound));
    }

    /** Une flèche validée ne se modifie que via l'écran de rectification. */
    public static function alreadyFinalized(): self
    {
        return new self('This result is already finalized and cannot be changed');
    }
}

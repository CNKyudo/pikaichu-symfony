<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Bilan d'un import de participants.
 *
 * Reprend les deux listes que `participants#import` remonte à l'utilisateur :
 * les archers absents de la base fédérale et ceux qui n'ont pas pu être créés.
 */
final readonly class ParticipantImportReport
{
    /**
     * @param list<string> $notFound archers importés sans licencié correspondant
     * @param list<string> $failed   lignes rejetées à l'enregistrement
     */
    public function __construct(
        public int $imported = 0,
        public array $notFound = [],
        public array $failed = [],
    ) {
    }
}

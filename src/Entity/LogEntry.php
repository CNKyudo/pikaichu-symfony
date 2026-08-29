<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Loggable\Entity\MappedSuperclass\AbstractLogEntry;
use Gedmo\Loggable\Entity\Repository\LogEntryRepository;

/**
 * Piste d'audit, reprise du gem `audited` côté Rails (table `audits`, jamais
 * consultée depuis l'interface Rails elle-même — voir MIGRATION.md).
 *
 * Sous-classe concrète de `AbstractLogEntry` de Gedmo/Doctrine Extensions :
 * Doctrine ne scanne que `src/Entity/` pour ses entités, donc le mapping
 * fourni par Gedmo (`Gedmo\Loggable\Entity\LogEntry`, dans `vendor/`) n'y
 * serait pas découvert sans ajouter un chemin de mapping séparé. Une
 * sous-classe locale est l'intégration standard recommandée pour éviter ça.
 *
 * @extends AbstractLogEntry<object>
 */
#[ORM\Entity(repositoryClass: LogEntryRepository::class)]
#[ORM\Table(name: 'ext_log_entries')]
#[ORM\Index(name: 'log_class_lookup_idx', columns: ['object_class'])]
#[ORM\Index(name: 'log_date_lookup_idx', columns: ['logged_at'])]
#[ORM\Index(name: 'log_user_lookup_idx', columns: ['username'])]
#[ORM\Index(name: 'log_version_lookup_idx', columns: ['object_id', 'object_class', 'version'])]
final class LogEntry extends AbstractLogEntry
{
}

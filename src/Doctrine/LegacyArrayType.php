<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Rétablit le type `array` (sérialisation PHP dans une colonne texte),
 * retiré de Doctrine DBAL 4. Nécessaire pour `Gedmo\Loggable\Entity\LogEntry`
 * (colonne `data`), qui déclare encore ce type par son nom littéral — voir
 * MIGRATION.md pour le contexte (piste d'audit).
 */
final class LegacyArrayType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : serialize($value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        \assert(\is_string($value));
        $unserialized = @unserialize($value);
        if (false === $unserialized && 'b:0;' !== $value) {
            throw new ConversionException(\sprintf('Could not convert database value "%s" to a PHP array.', $value));
        }

        return $unserialized;
    }
}

<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/../src',
        __DIR__.'/../tests',
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::DEAD_CODE,
        SetList::CODING_STYLE,
        SetList::TYPE_DECLARATION,
    ])
    // `importNames: false` est volontaire : php-cs-fixer (@Symfony) laisse les
    // classes globales en nom pleinement qualifié (\DateTimeImmutable). Sans cela,
    // les deux outils se contrediraient à chaque exécution de `make fix`.
    ->withImportNames(importNames: false, removeUnusedImports: true);

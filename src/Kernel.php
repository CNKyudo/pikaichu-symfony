<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        // Fixé ici plutôt que dans le php.ini du serveur : ce dernier varie
        // selon l'environnement (docker local, VPS de prod déployé hors
        // docker) et un décalage entre les deux a déjà causé des horaires
        // erronés dans l'historique des taikai (`_timeline.html.twig`).
        date_default_timezone_set('Europe/Paris');

        parent::__construct($environment, $debug);
    }
}

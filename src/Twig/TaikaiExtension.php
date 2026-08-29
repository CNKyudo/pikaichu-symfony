<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Taikai;
use App\Enum\TaikaiState;
use App\Service\TaikaiStateMachine;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la machine à états au gabarit partagé `taikai/_layout.html.twig`
 * (titre + frise d'avancement), repris de la layout Rails `taikai` appliquée
 * à tous les écrans de gestion d'un taikai. Évite de faire transiter
 * `allowed_transitions` par chaque contrôleur qui rend ce gabarit.
 */
final class TaikaiExtension extends AbstractExtension
{
    public function __construct(
        private readonly TaikaiStateMachine $stateMachine,
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('taikai_allowed_transitions', $this->allowedTransitions(...)),
            new TwigFunction('taikai_states', $this->states(...)),
        ];
    }

    /** @return list<TaikaiState> */
    private function allowedTransitions(Taikai $taikai): array
    {
        return $this->stateMachine->getAllowedTransitions($taikai);
    }

    /** @return list<TaikaiState> */
    private function states(): array
    {
        return TaikaiState::cases();
    }
}

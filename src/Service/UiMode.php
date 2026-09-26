<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

final readonly class UiMode
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function isBeta(): bool
    {
        return 'beta' === $this->requestStack->getSession()->get('ui', 'classic');
    }
}

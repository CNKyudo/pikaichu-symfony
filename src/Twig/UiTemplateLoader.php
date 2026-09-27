<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;

final readonly class UiTemplateLoader implements LoaderInterface
{
    public function __construct(
        private FilesystemLoader $inner,
        private RequestStack $requestStack,
    ) {
    }

    public function exists(string $name): bool
    {
        return $this->inner->exists($this->resolveTemplateName($name));
    }

    public function getSourceContext(string $name): Source
    {
        return $this->inner->getSourceContext(
            $this->resolveTemplateName($name),
        );
    }

    public function getCacheKey(string $name): string
    {
        return $this->inner->getCacheKey(
            $this->resolveTemplateName($name),
        );
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->inner->isFresh(
            $this->resolveTemplateName($name),
            $time,
        );
    }

    private function resolveTemplateName(string $name): string
    {
        if (!$this->isBeta()) {
            return $name;
        }

        // Ne jamais transformer les templates avec espace de noms tels que @Security/...
        if (str_starts_with($name, '@')) {
            return $name;
        }

        if (str_starts_with($name, 'beta/')) {
            return $name;
        }

        $betaName = 'beta/'.ltrim($name, '/');

        if ($this->inner->exists($betaName)) {
            return $betaName;
        }

        // La bêta n'a pas encore cette page : utiliser la version classique.
        return $name;
    }

    private function isBeta(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return false;
        }

        return 'beta' === $request->getSession()->get('ui', 'classic');
    }
}

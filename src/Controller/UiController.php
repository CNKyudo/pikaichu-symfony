<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class UiController extends AbstractController
{
    #[Route(
        '/ui/{mode}',
        name: 'ui_switch',
        requirements: ['mode' => 'classic|beta'],
        methods: ['GET'],
    )]
    public function switch(Request $request, string $mode): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $request->getSession()->set('ui', $mode);

        $referer = $request->headers->get('referer');

        if (null !== $referer) {
            $refererParts = parse_url($referer);

            $sameHost = ($refererParts['host'] ?? null) === $request->getHost();
            $sameScheme = ($refererParts['scheme'] ?? null) === $request->getScheme();

            if ($sameHost && $sameScheme) {
                return $this->redirect($referer);
            }
        }

        return $this->redirectToRoute('app_home');
    }
}

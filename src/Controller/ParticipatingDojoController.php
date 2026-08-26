<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Security\Voter\TaikaiVoter;
use App\Service\DrawService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Actions sur un club hôte.
 *
 * Seul le tirage au sort est porté pour l'instant ; le CRUD complet reste à
 * faire (voir MIGRATION.md).
 */
#[Route('/taikais/{taikaiId}/participating-dojos', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class ParticipatingDojoController extends AbstractController
{
    /**
     * Tirage au sort de l'ordre de passage du club hôte.
     */
    #[Route('/{id}/draw', name: 'app_participating_dojo_draw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function draw(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] ParticipatingDojo $participatingDojo,
        Request $request,
        DrawService $drawService,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);

        if ($participatingDojo->getTaikai() !== $taikai) {
            throw $this->createNotFoundException('Participating dojo does not belong to this taikai');
        }

        if (!$this->isCsrfTokenValid('draw'.$participatingDojo->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'marking.invalid_token');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        $error = $drawService->draw($participatingDojo);

        if (null !== $error) {
            $this->addFlash('error', $error);
        } else {
            $this->addFlash('success', 'draw.success');
        }

        return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
    }
}

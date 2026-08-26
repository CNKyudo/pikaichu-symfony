<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Participant;
use App\Entity\Result;
use App\Entity\Taikai;
use App\Enum\ResultStatus;
use App\Exception\MarkingException;
use App\Security\Voter\TaikaiVoter;
use App\Service\MarkingService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Feuille de marque : saisie des flèches pendant le tir.
 */
#[Route('/taikais/{taikaiId}/marking', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class MarkingController extends AbstractController
{
    public function __construct(
        private readonly MarkingService $markingService,
    ) {
    }

    #[Route('', name: 'app_marking_show', methods: ['GET'])]
    public function show(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        Request $request,
    ): Response {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);

        // Filtre d'affichage : « toutes les séries » ou une série précise.
        $round = $request->query->getInt('round');
        $round = $round >= 1 && $round <= $taikai->getNumRounds() ? $round : null;

        return $this->render('marking/show.html.twig', [
            'taikai' => $taikai,
            'round' => $round,
            'rounds' => range(1, $taikai->getNumRounds()),
        ]);
    }

    /**
     * Marque la prochaine flèche du participant.
     */
    #[Route('/{participantId}/add', name: 'app_marking_add', requirements: ['participantId' => '\d+'], methods: ['POST'])]
    public function add(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participantId')] Participant $participant,
        Request $request,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);
        $this->assertBelongsToTaikai($taikai, $participant);

        $status = ResultStatus::tryFrom((string) $request->request->get('status'));
        if (!$status instanceof ResultStatus) {
            $this->addFlash('error', 'marking.unknown_status');

            return $this->redirectToMarking($taikai);
        }

        $value = $request->request->has('value') ? $request->request->getInt('value') : null;

        try {
            $this->markingService->addResult($participant, $status, $value);
        } catch (MarkingException $markingException) {
            $this->addFlash('error', $markingException->getMessage());
        }

        return $this->redirectToMarking($taikai);
    }

    /**
     * Fait tourner le statut d'une flèche pas encore validée.
     */
    #[Route('/results/{resultId}/rotate', name: 'app_marking_rotate', requirements: ['resultId' => '\d+'], methods: ['POST'])]
    public function rotate(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'resultId')] Result $result,
        Request $request,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);

        if (!$this->isCsrfTokenValid('rotate'.$result->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'marking.invalid_token');

            return $this->redirectToMarking($taikai);
        }

        try {
            $this->markingService->rotateResult($result);
        } catch (MarkingException $markingException) {
            $this->addFlash('error', $markingException->getMessage());
        }

        return $this->redirectToMarking($taikai);
    }

    /**
     * Valide une série : c'est la « coche bleue » de la feuille de marque.
     */
    #[Route('/{participantId}/finalize/{round}', name: 'app_marking_finalize', requirements: ['participantId' => '\d+', 'round' => '\d+'], methods: ['POST'])]
    public function finalize(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participantId')] Participant $participant,
        int $round,
        Request $request,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);
        $this->assertBelongsToTaikai($taikai, $participant);

        if (!$this->isCsrfTokenValid('finalize'.$participant->getId().'-'.$round, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'marking.invalid_token');

            return $this->redirectToMarking($taikai);
        }

        $this->markingService->finalizeRound($participant, $round);

        return $this->redirectToMarking($taikai);
    }

    /**
     * Empêche de marquer un participant d'un autre taikai via une URL forgée.
     */
    private function assertBelongsToTaikai(Taikai $taikai, Participant $participant): void
    {
        if ($participant->getTaikai() !== $taikai) {
            throw $this->createNotFoundException('Participant does not belong to this taikai');
        }
    }

    private function redirectToMarking(Taikai $taikai): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_marking_show', ['taikaiId' => $taikai->getId()]);
    }
}

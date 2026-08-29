<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Participant;
use App\Entity\Result;
use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Enum\ResultStatus;
use App\Exception\MarkingException;
use App\Repository\TaikaiMatchRepository;
use App\Security\Voter\TaikaiVoter;
use App\Service\MarkingService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Feuille de marque : saisie des flèches pendant le tir.
 *
 * Reprend `marking_controller#show`/`#show_match` : un tournoi à matchs n'a
 * jamais de score « sans match » (voir `ScoreInitializer`), donc `add()` et
 * `finalize()` acceptent un `matchId` optionnel, transmis en champ caché par
 * `marking/_participant_row.html.twig` lorsqu'on marque depuis la feuille
 * d'un match plutôt que depuis la feuille générale du taikai.
 */
#[Route('/taikais/{taikaiId}/marking', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class MarkingController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    public function __construct(
        private readonly MarkingService $markingService,
        private readonly TaikaiMatchRepository $matches,
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
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);
        $this->assertBelongsTo($participant->getTaikai(), $taikai, 'Participant does not belong to this taikai');

        $match = $this->resolveMatch($taikai, $request);

        $status = ResultStatus::tryFrom((string) $request->request->get('status'));
        if (!$status instanceof ResultStatus) {
            $this->addFlash('error', 'marking.unknown_status');

            return $this->redirectToMarking($taikai, $match);
        }

        $value = $request->request->has('value') ? $request->request->getInt('value') : null;

        try {
            $this->markingService->addResult($participant, $status, $value, $match);
        } catch (MarkingException $markingException) {
            $this->addFlash('error', $markingException->getMessage());
        }

        return $this->redirectToMarking($taikai, $match);
    }

    /**
     * Fait tourner le statut d'une flèche pas encore validée.
     */
    #[Route('/results/{resultId}/rotate', name: 'app_marking_rotate', requirements: ['resultId' => '\d+'], methods: ['POST'])]
    public function rotate(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'resultId')] Result $result,
        Request $request,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);

        if (!$this->isCsrfTokenValid('rotate'.$result->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'marking.invalid_token');

            return $this->redirectToMarking($taikai, $result->getMatch());
        }

        try {
            $this->markingService->rotateResult($result);
        } catch (MarkingException $markingException) {
            $this->addFlash('error', $markingException->getMessage());
        }

        return $this->redirectToMarking($taikai, $result->getMatch());
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
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);
        $this->assertBelongsTo($participant->getTaikai(), $taikai, 'Participant does not belong to this taikai');

        $match = $this->resolveMatch($taikai, $request);

        if (!$this->isCsrfTokenValid('finalize'.$participant->getId().'-'.$round, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'marking.invalid_token');

            return $this->redirectToMarking($taikai, $match);
        }

        $this->markingService->finalizeRound($participant, $round, $match);

        return $this->redirectToMarking($taikai, $match);
    }

    /**
     * Résout le `matchId` optionnel envoyé par la feuille de marque d'un match,
     * en s'assurant qu'il appartient bien au taikai visé.
     */
    private function resolveMatch(Taikai $taikai, Request $request): ?TaikaiMatch
    {
        $matchId = $request->request->get('matchId');
        if (null === $matchId || '' === $matchId) {
            return null;
        }

        $match = $this->matches->find((int) $matchId);
        if (!$match instanceof TaikaiMatch) {
            throw $this->createNotFoundException('Match not found');
        }

        $this->assertBelongsTo($match->getTaikai(), $taikai, 'Match does not belong to this taikai');

        return $match;
    }

    private function redirectToMarking(Taikai $taikai, ?TaikaiMatch $match = null): RedirectResponse
    {
        if (null !== $match) {
            return $this->redirectToRoute('app_marking_show_match', ['taikaiId' => $taikai->getId(), 'matchId' => $match->getId()]);
        }

        return $this->redirectToRoute('app_marking_show', ['taikaiId' => $taikai->getId()]);
    }
}

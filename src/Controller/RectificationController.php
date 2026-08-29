<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Result;
use App\Entity\Taikai;
use App\Entity\User;
use App\Enum\ResultStatus;
use App\Enum\TaikaiScoring;
use App\Form\ResultRectificationType;
use App\Security\Voter\TaikaiVoter;
use App\Service\MarkingService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rectification d'une flèche déjà validée, portée de `rectification_controller`.
 *
 * Réservée aux administrateurs du taikai (`TaikaiVoter::RECTIFY`), à la
 * différence de la saisie elle-même qui est ouverte à un cercle plus large de
 * rôles — voir la note dans `TaikaiVoter`.
 */
#[Route('/taikais/{taikaiId}/rectification', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class RectificationController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    public function __construct(
        private readonly MarkingService $markingService,
    ) {
    }

    #[Route('', name: 'app_rectification_index', methods: ['GET'])]
    public function index(#[MapEntity(id: 'taikaiId')] Taikai $taikai): Response
    {
        $this->denyAccessUnlessGranted(TaikaiVoter::RECTIFY, $taikai);

        return $this->render('rectification/index.html.twig', [
            'taikai' => $taikai,
            'rounds' => range(1, $taikai->getNumRounds()),
        ]);
    }

    #[Route('/{resultId}/edit', name: 'app_rectification_edit', requirements: ['resultId' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'resultId')] Result $result,
        Request $request,
    ): Response {
        $this->denyAccessUnlessGranted(TaikaiVoter::RECTIFY, $taikai);
        $this->assertBelongsTo($result->getTaikai(), $taikai, 'Result does not belong to this taikai');

        $enteki = TaikaiScoring::Enteki === $taikai->getScoring();

        $form = $this->createForm(ResultRectificationType::class, [
            'status' => $result->getStatus(),
            'value' => $result->getValue(),
        ], ['enteki' => $enteki]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{status?: ResultStatus, value?: int} $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            if ($enteki) {
                $value = $data['value'] ?? 0;
                // La valeur fait foi en enteki : le statut n'est qu'un dérivé
                // (0 -> manqué, sinon touché), comme `Result::setValue()`.
                $status = 0 === $value ? ResultStatus::Miss : ResultStatus::Hit;
                $this->markingService->rectify($result, $status, $value, $user);
            } else {
                $status = $data['status'] ?? ResultStatus::Hit;
                $this->markingService->rectify($result, $status, null, $user);
            }

            $this->addFlash('success', [
                'key' => 'rectification.rectified',
                'parameters' => [
                    '%index%' => $result->getIndex() ?? 0,
                    '%round%' => $result->getRound() ?? 0,
                    '%participant%' => $result->getScore()?->getParticipant()?->getDisplayName() ?? '?',
                    '%result%' => (string) $result,
                ],
            ]);

            return $this->redirectToRoute('app_marking_show', ['taikaiId' => $taikai->getId()]);
        }

        return $this->render('rectification/edit.html.twig', [
            'taikai' => $taikai,
            'result' => $result,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

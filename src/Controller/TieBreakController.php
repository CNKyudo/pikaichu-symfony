<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Taikai;
use App\Entity\User;
use App\Enum\TaikaiForm;
use App\Security\Voter\TaikaiVoter;
use App\Service\TieBreakService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ajustement manuel des rangs à l'entrée du tie-break, porté de `tie_break_controller`.
 *
 * Un tournoi « 2 en 1 » bascule entre classement individuel et par équipe via
 * le paramètre `individual` ; les autres formes n'ont qu'une seule vue possible.
 */
#[Route('/taikais/{taikaiId}/tie-break', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class TieBreakController extends AbstractController
{
    public function __construct(
        private readonly TieBreakService $tieBreakService,
    ) {
    }

    #[Route('', name: 'app_tie_break_edit', methods: ['GET'])]
    public function edit(#[MapEntity(id: 'taikaiId')] Taikai $taikai, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TaikaiVoter::TIE_BREAK, $taikai);

        $individual = $this->resolveIndividual($taikai, $request);

        return $this->render('tie_break/edit.html.twig', [
            'taikai' => $taikai,
            'individual' => $individual,
            'groups' => $individual
                ? $this->tieBreakService->getParticipantGroups($taikai)
                : $this->tieBreakService->getTeamGroups($taikai),
        ]);
    }

    #[Route('', name: 'app_tie_break_update', methods: ['POST'])]
    public function update(#[MapEntity(id: 'taikaiId')] Taikai $taikai, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(TaikaiVoter::TIE_BREAK, $taikai);

        if (!$this->isCsrfTokenValid('tie_break'.$taikai->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'tie_break.invalid_token');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        /** @var User $user */
        $user = $this->getUser();

        $ranksById = [];
        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('rank');
        foreach ($submitted as $id => $rank) {
            $ranksById[(int) $id] = (int) $rank;
        }

        if ($this->resolveIndividual($taikai, $request)) {
            $this->tieBreakService->applyParticipantRanks($taikai, $ranksById, $user);
        } else {
            $this->tieBreakService->applyTeamRanks($taikai, $ranksById, $user);
        }

        $this->addFlash('success', 'tie_break.success');

        return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
    }

    /**
     * Un tournoi « 2 en 1 » affiche le classement par équipe par défaut, et ne
     * bascule sur l'individuel que si `individual=true` est explicitement
     * demandé — comme `params[:individual] == "true"` côté Rails.
     */
    private function resolveIndividual(Taikai $taikai, Request $request): bool
    {
        return match ($taikai->getForm()) {
            TaikaiForm::Individual => true,
            TaikaiForm::Team, TaikaiForm::Matches => false,
            TaikaiForm::TwoInOne => 'true' === ($request->query->get('individual') ?? $request->request->get('individual')),
            null => throw new \LogicException('Unknown taikai form'),
        };
    }
}

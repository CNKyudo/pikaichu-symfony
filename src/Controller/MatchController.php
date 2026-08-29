<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Taikai;
use App\Entity\TaikaiMatch;
use App\Entity\Team;
use App\Security\Voter\TaikaiVoter;
use App\Service\MatchService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau final d'un tournoi à matchs, porté de `matches_controller`.
 *
 * Comme `teams_controller`, `matches_controller` n'appelle jamais `authorize`
 * côté Rails ; réservé à `TaikaiVoter::EDIT`, comme le reste des écrans
 * d'administration du taikai.
 */
#[Route('/taikais/{taikaiId}/matches', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class MatchController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    public function __construct(
        private readonly MatchService $matchService,
    ) {
    }

    #[Route('', name: 'app_match_index', methods: ['GET'])]
    public function index(#[MapEntity(id: 'taikaiId')] Taikai $taikai): Response
    {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);

        $byLevel = [];
        foreach ($taikai->getMatches() as $match) {
            $byLevel[$match->getLevel()][] = $match;
        }

        foreach ($byLevel as $level => $matches) {
            usort($matches, static fn (TaikaiMatch $a, TaikaiMatch $b): int => $a->getIndex() <=> $b->getIndex());
            $byLevel[$level] = $matches;
        }

        ksort($byLevel);

        return $this->render('match/index.html.twig', [
            'taikai' => $taikai,
            'byLevel' => $byLevel,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_match_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] TaikaiMatch $match,
        Request $request,
    ): Response {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);
        $this->assertBelongsTo($match->getTaikai(), $taikai, 'Match does not belong to this taikai');

        $teams = $taikai->getTeams();
        usort($teams, static fn (Team $a, Team $b): int => strnatcasecmp((string) $a->getShortname(), (string) $b->getShortname()));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('match'.$match->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'match.invalid_token');

                return $this->redirectToRoute('app_match_index', ['taikaiId' => $taikai->getId()]);
            }

            $team1 = $this->findTeam($teams, $request->request->get('team1_id'));
            $team2 = $this->findTeam($teams, $request->request->get('team2_id'));
            $winner = $request->request->get('winner');

            $error = $this->matchService->updateTeams($match, $team1, $team2);
            if (null === $error && null !== $winner && '' !== $winner) {
                $error = $this->matchService->selectWinner($match, (int) $winner);
            }

            if (null !== $error) {
                $this->addFlash('error', $error);

                return $this->render('match/edit.html.twig', [
                    'taikai' => $taikai,
                    'match' => $match,
                    'teams' => $teams,
                ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', 'match.updated');

            return $this->redirectToRoute('app_match_index', ['taikaiId' => $taikai->getId()]);
        }

        return $this->render('match/edit.html.twig', [
            'taikai' => $taikai,
            'match' => $match,
            'teams' => $teams,
        ]);
    }

    /**
     * Feuille de marque d'un match, porté de `marking#show_match`.
     */
    #[Route('/{matchId}/marking', name: 'app_marking_show_match', requirements: ['matchId' => '\d+'], methods: ['GET'])]
    public function showMatch(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'matchId')] TaikaiMatch $match,
    ): Response {
        $this->denyAccessUnlessGranted(TaikaiVoter::MARK, $taikai);
        $this->assertBelongsTo($match->getTaikai(), $taikai, 'Match does not belong to this taikai');

        return $this->render('marking/show_match.html.twig', [
            'taikai' => $taikai,
            'match' => $match,
            'rounds' => range(1, $taikai->getNumRounds()),
        ]);
    }

    /**
     * Désigne automatiquement l'équipe gagnante, à partir du score le plus haut.
     */
    #[Route('/{id}/select-winner', name: 'app_match_select_winner', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function selectWinner(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] TaikaiMatch $match,
        Request $request,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);
        $this->assertBelongsTo($match->getTaikai(), $taikai, 'Match does not belong to this taikai');

        if (!$this->isCsrfTokenValid('match'.$match->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'match.invalid_token');

            return $this->redirectToRoute('app_match_index', ['taikaiId' => $taikai->getId()]);
        }

        $score1 = $match->getScore(1)?->toScoreValue();
        $score2 = $match->getScore(2)?->toScoreValue();
        $winner = null !== $score1 && null !== $score2 && $score1->compareTo($score2) > 0 ? 1 : 2;

        $error = $this->matchService->selectWinner($match, $winner);
        if (null !== $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_match_index', ['taikaiId' => $taikai->getId()]);
    }

    /** @param list<Team> $teams */
    private function findTeam(array $teams, mixed $id): ?Team
    {
        if (null === $id || '' === $id) {
            return null;
        }

        foreach ($teams as $team) {
            if ((string) $team->getId() === (string) $id) {
                return $team;
            }
        }

        return null;
    }
}

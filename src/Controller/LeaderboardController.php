<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Taikai;
use App\Enum\TaikaiForm;
use App\Service\LeaderboardService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableaux de résultats, en accès authentifié et en affichage public.
 */
#[Route('/taikais/{taikaiId}/leaderboard', requirements: ['taikaiId' => '\d+'])]
final class LeaderboardController extends AbstractController
{
    public function __construct(
        private readonly LeaderboardService $leaderboardService,
    ) {
    }

    /**
     * Classement individuel (ou par équipes pour un taikai en équipes).
     */
    #[Route('', name: 'app_leaderboard_show', methods: ['GET'])]
    public function show(#[MapEntity(id: 'taikaiId')] Taikai $taikai): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('leaderboard/show.html.twig', $this->buildViewData($taikai));
    }

    /**
     * Même classement, sans authentification, pour projection en salle.
     *
     * Pour un 2-en-1, contrairement à `show`, la projection publique affiche
     * un seul classement à la fois — équipes par défaut, individuel si
     * `?individual` est présent — comme `leaderboard_controller#public` côté
     * Rails. Deux classements superposés sur un écran de projection seraient
     * illisibles.
     */
    #[Route('/public', name: 'app_leaderboard_public', methods: ['GET'])]
    public function public(#[MapEntity(id: 'taikaiId')] Taikai $taikai, Request $request): Response
    {
        if (TaikaiForm::TwoInOne === $taikai->getForm()) {
            $data = $this->emptyViewData($taikai);
            if ($request->query->has('individual')) {
                [$data['individual'], $data['by_dojo']] = $this->leaderboardService->computeIndividualLeaderboard($taikai);
            } else {
                [$data['teams'], $data['by_dojo']] = $this->leaderboardService->computeTeamLeaderboard($taikai);
            }

            return $this->render('leaderboard/public.html.twig', $data);
        }

        return $this->render('leaderboard/public.html.twig', $this->buildViewData($taikai));
    }

    /**
     * Classement par équipes seul d'un 2-en-1, porté de `leaderboard#show_2in1`.
     * `show` affiche déjà équipes et individuel ensemble pour un 2-en-1 ; cet
     * écran isole les équipes, par exemple pour une projection ciblée.
     */
    #[Route('/2in1', name: 'app_leaderboard_2in1', methods: ['GET'])]
    public function showTeamOnly(#[MapEntity(id: 'taikaiId')] Taikai $taikai): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $data = $this->emptyViewData($taikai);
        [$data['teams'], $data['by_dojo']] = $this->leaderboardService->computeTeamLeaderboard($taikai);

        return $this->render('leaderboard/show_2in1.html.twig', $data);
    }

    /** @return array<string, mixed> */
    private function emptyViewData(Taikai $taikai): array
    {
        return [
            'taikai' => $taikai,
            'individual' => [],
            'teams' => [],
            'by_dojo' => [],
            'podium' => [],
            'matches' => [],
        ];
    }

    /**
     * Prépare les données du classement selon la forme du taikai.
     *
     * @return array<string, mixed>
     */
    private function buildViewData(Taikai $taikai): array
    {
        $data = $this->emptyViewData($taikai);

        switch ($taikai->getForm()) {
            case TaikaiForm::Individual:
                [$data['individual'], $data['by_dojo']] = $this->leaderboardService
                    ->computeIndividualLeaderboard($taikai);
                break;

            case TaikaiForm::Team:
                [$data['teams'], $data['by_dojo']] = $this->leaderboardService
                    ->computeTeamLeaderboard($taikai);
                break;

            case TaikaiForm::TwoInOne:
                // Un 2-en-1 se classe à la fois par équipes et en individuel.
                [$data['teams'], $data['by_dojo']] = $this->leaderboardService
                    ->computeTeamLeaderboard($taikai);
                [$data['individual']] = $this->leaderboardService
                    ->computeIndividualLeaderboard($taikai);
                break;

            case TaikaiForm::Matches:
                [$data['podium'], $data['matches']] = $this->leaderboardService
                    ->computeMatchesLeaderboard($taikai);
                break;

            case null:
                break;
        }

        return $data;
    }
}

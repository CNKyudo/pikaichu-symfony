<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Taikai;
use App\Enum\TaikaiForm;
use App\Service\LeaderboardService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
     */
    #[Route('/public', name: 'app_leaderboard_public', methods: ['GET'])]
    public function public(#[MapEntity(id: 'taikaiId')] Taikai $taikai): Response
    {
        return $this->render('leaderboard/public.html.twig', $this->buildViewData($taikai));
    }

    /**
     * Prépare les données du classement selon la forme du taikai.
     *
     * @return array<string, mixed>
     */
    private function buildViewData(Taikai $taikai): array
    {
        $data = [
            'taikai' => $taikai,
            'individual' => [],
            'teams' => [],
            'by_dojo' => [],
            'podium' => [],
            'matches' => [],
        ];

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

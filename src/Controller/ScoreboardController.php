<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Participant;
use App\Entity\Result;
use App\Entity\Scoreboard;
use App\Entity\Tachi;
use App\Entity\Taikai;
use App\Repository\ScoreboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Affichage public d'un tachi par clé d'API, porté de `scoreboard_controller`.
 *
 * Accessible sans authentification, comme côté Rails
 * (`allow_unauthenticated_access only: [ :show ]`). Sert à projeter en salle
 * l'avancement du tir sur une cible, avec un rafraîchissement côté client.
 */
final class ScoreboardController extends AbstractController
{
    #[Route(
        '/scoreboard/{apiKey}.{_format}',
        name: 'app_scoreboard_show',
        requirements: ['_format' => 'html|json'],
        defaults: ['_format' => 'html'],
        methods: ['GET'],
    )]
    public function show(string $apiKey, string $_format, ScoreboardRepository $scoreboards): Response
    {
        $scoreboard = $scoreboards->findOneByApiKey($apiKey);
        if (!$scoreboard instanceof Scoreboard) {
            throw $this->createNotFoundException('Scoreboard not found');
        }

        $participatingDojo = $scoreboard->getParticipatingDojo();
        $taikai = $participatingDojo?->getTaikai();
        if (!$taikai instanceof Taikai) {
            throw $this->createNotFoundException('Scoreboard is not attached to any taikai');
        }

        $previousTachi = $participatingDojo->getPreviousTachi();
        $currentTachi = $participatingDojo->getCurrentTachi();

        // Le tachi qui vient de se terminer reste affiché le temps du délai
        // configuré, pour laisser au public le temps de lire le dernier tir.
        $displayTachi = $this->isRecentlyUpdated($previousTachi, $scoreboard->getDelay())
            ? $previousTachi
            : $currentTachi;

        if ('json' === $_format) {
            return $this->json($this->formatTachi($taikai, $displayTachi));
        }

        return $this->render('scoreboard/show.html.twig', [
            'scoreboard' => $scoreboard,
            'displayTachi' => $displayTachi,
        ]);
    }

    private function isRecentlyUpdated(?Tachi $tachi, int $delaySeconds): bool
    {
        if (!$tachi instanceof Tachi) {
            return false;
        }

        $updatedAt = $tachi->getUpdatedAt();

        return null !== $updatedAt && $updatedAt > new \DateTimeImmutable(\sprintf('-%d seconds', $delaySeconds));
    }

    /** @return array<string, mixed> */
    private function formatTachi(Taikai $taikai, ?Tachi $tachi): array
    {
        $data = [
            'taikai' => [
                'name' => $taikai->getName(),
                'shortname' => $taikai->getShortname(),
                'form' => $taikai->getForm()?->value,
                'num_targets' => $taikai->getNumTargets(),
                'num_arrows' => $taikai->getNumArrows(),
                'num_rounds' => $taikai->getNumRounds(),
                'scoring' => $taikai->getScoring()->value,
                'total_num_arrows' => $taikai->getTotalNumArrows(),
            ],
        ];

        if (!$tachi instanceof Tachi) {
            return $data;
        }

        $data['tachi'] = [
            'index' => $tachi->getIndex(),
            'round' => $tachi->getRound(),
            'participating_dojo' => [
                'name' => $tachi->getParticipatingDojo()?->getDisplayName(),
            ],
            'participants' => array_map(
                function (Participant $participant) use ($tachi): array {
                    $score = $participant->getScore($tachi->getMatch());

                    return [
                        'name' => $participant->getDisplayName(),
                        'index' => $participant->getIndex(),
                        'score' => [
                            'results' => null === $score ? [] : array_map(
                                static fn (Result $result): array => [
                                    'status' => $result->getStatus()?->value,
                                    'value' => $result->getValue(),
                                    'final' => $result->isFinal(),
                                ],
                                $score->getResultsForRound($tachi->getRound()),
                            ),
                        ],
                    ];
                },
                $tachi->getParticipants(),
            ),
            'updated_at' => $tachi->getUpdatedAt()?->format(\DATE_ATOM),
        ];

        return $data;
    }
}

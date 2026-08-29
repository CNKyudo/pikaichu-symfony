<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Entity\Team;
use App\Service\TeamingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Composition des équipes d'un club hôte, portée de `teaming_controller`.
 *
 * Comme `TeamController`, réservé à `ParticipatingDojoVoter::EDIT` bien que
 * `teaming_controller` n'appelle jamais `authorize` côté Rails.
 */
#[Route(
    '/taikais/{taikaiId}/participating-dojos/{participatingDojoId}/teaming',
    requirements: ['taikaiId' => '\d+', 'participatingDojoId' => '\d+'],
)]
#[IsGranted('ROLE_USER')]
final class TeamingController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamingService $teamingService,
    ) {
    }

    #[Route('', name: 'app_teaming_edit', methods: ['GET'])]
    public function edit(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
    ): Response {
        $this->assertHostClub($taikai, $participatingDojo);

        $teams = $participatingDojo->getTeams()->toArray();
        usort($teams, static fn (Team $a, Team $b): int => strnatcasecmp((string) $a->getShortname(), (string) $b->getShortname()));

        return $this->render('teaming/edit.html.twig', [
            'taikai' => $taikai,
            'participatingDojo' => $participatingDojo,
            'teams' => $teams,
            'unteamedParticipants' => $participatingDojo->getUnteamedParticipants(),
        ]);
    }

    #[Route('/create-team', name: 'app_teaming_create_team', methods: ['POST'])]
    public function createTeam(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): RedirectResponse {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertValidToken($participatingDojo, $request);

        $shortname = (string) $request->request->get('shortname', '');
        $violations = $this->teamingService->createTeam($participatingDojo, $shortname);

        $taken = false;
        foreach ($violations as $violation) {
            if ('team.shortname.already_used' === $violation->getMessageTemplate()) {
                $taken = true;

                break;
            }
        }

        if ($taken) {
            $this->addFlash('error', [
                'key' => 'teaming.taken_team_shortname',
                'parameters' => ['%shortname%' => $shortname],
            ]);
        } elseif ('' === trim($shortname)) {
            $this->addFlash('error', 'teaming.empty_team_shortname');
        }

        return $this->redirectToEdit($taikai, $participatingDojo);
    }

    #[Route('/move', name: 'app_teaming_move', methods: ['POST'])]
    public function move(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): RedirectResponse {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertValidToken($participatingDojo, $request);

        $participant = $this->entityManager->getRepository(Participant::class)
            ->find((int) $request->request->get('participant_id'));
        if (!$participant instanceof Participant) {
            throw $this->createNotFoundException('Participant does not belong to this participating dojo');
        }

        $this->assertBelongsTo($participant->getParticipatingDojo(), $participatingDojo, 'Participant does not belong to this participating dojo');

        $teamId = $request->request->get('team_id');
        $team = null;
        if (null !== $teamId && '' !== $teamId) {
            $team = $this->entityManager->getRepository(Team::class)->find((int) $teamId);
            if (!$team instanceof Team) {
                throw $this->createNotFoundException('Team does not belong to this participating dojo');
            }

            $this->assertBelongsTo($team->getParticipatingDojo(), $participatingDojo, 'Team does not belong to this participating dojo');
        }

        $this->teamingService->moveParticipant($participant, $team);

        return $this->redirectToEdit($taikai, $participatingDojo);
    }

    #[Route('/clear', name: 'app_teaming_clear', methods: ['POST'])]
    public function clear(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): RedirectResponse {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertValidToken($participatingDojo, $request);

        $this->teamingService->clear($participatingDojo);

        return $this->redirectToEdit($taikai, $participatingDojo);
    }

    #[Route('/form-randomly', name: 'app_teaming_form_randomly', methods: ['POST'])]
    public function formRandomly(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): RedirectResponse {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertValidToken($participatingDojo, $request);

        $prefix = trim((string) $request->request->get('prefix', ''));
        if ('' === $prefix) {
            $this->addFlash('error', 'teaming.empty_team_prefix');
        } else {
            $this->teamingService->formRandomly($participatingDojo, $prefix);
        }

        return $this->redirectToEdit($taikai, $participatingDojo);
    }

    private function redirectToEdit(Taikai $taikai, ParticipatingDojo $participatingDojo): RedirectResponse
    {
        return $this->redirectToRoute('app_teaming_edit', [
            'taikaiId' => $taikai->getId(),
            'participatingDojoId' => $participatingDojo->getId(),
        ]);
    }

    private function assertValidToken(ParticipatingDojo $participatingDojo, Request $request): void
    {
        if (!$this->isCsrfTokenValid('teaming'.$participatingDojo->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }
}

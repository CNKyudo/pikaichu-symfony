<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Entity\Team;
use App\Form\TeamType;
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
 * Équipes d'un club hôte, portées de `teams_controller`.
 *
 * `teams_controller` n'appelle jamais `authorize` côté Rails : n'importe quel
 * utilisateur authentifié pourrait donc modifier les équipes d'un club hôte
 * qui ne lui appartient pas — la même élévation de privilèges que
 * `staffs_controller` (voir `MIGRATION.md`). Réservé à `ParticipatingDojoVoter::EDIT`
 * comme le reste des écrans du club hôte.
 */
#[Route(
    '/taikais/{taikaiId}/participating-dojos/{participatingDojoId}/teams',
    requirements: ['taikaiId' => '\d+', 'participatingDojoId' => '\d+'],
)]
#[IsGranted('ROLE_USER')]
final class TeamController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamingService $teamingService,
    ) {
    }

    #[Route('/new', name: 'app_team_new', methods: ['GET', 'POST'])]
    public function new(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): Response {
        $this->assertHostClub($taikai, $participatingDojo);

        $team = new Team();
        $participatingDojo->addTeam($team);

        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($team);
            $this->entityManager->flush();

            $this->addFlash('success', 'team.created');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        if ($form->isSubmitted()) {
            $participatingDojo->removeTeam($team);
        }

        return $this->render('team/new.html.twig', [
            'taikai' => $taikai,
            'participatingDojo' => $participatingDojo,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'app_team_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        #[MapEntity(id: 'id')] Team $team,
        Request $request,
    ): Response {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertBelongsTo($team->getParticipatingDojo(), $participatingDojo, 'Team does not belong to this participating dojo');

        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'team.updated');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        return $this->render('team/edit.html.twig', [
            'taikai' => $taikai,
            'participatingDojo' => $participatingDojo,
            'team' => $team,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Supprime l'équipe et, comme côté Rails (`dependent: :destroy`), ses
     * participants — voir la migration `Version20260827143052`.
     */
    #[Route('/{id}', name: 'app_team_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        #[MapEntity(id: 'id')] Team $team,
        Request $request,
    ): RedirectResponse {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertBelongsTo($team->getParticipatingDojo(), $participatingDojo, 'Team does not belong to this participating dojo');

        if (!$this->isCsrfTokenValid('delete'.$team->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'team.delete.invalid_token');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        $this->entityManager->remove($team);
        $this->entityManager->flush();

        $this->addFlash('success', 'team.deleted');

        return $this->redirectToHostClub($taikai, $participatingDojo);
    }

    /**
     * Réordonne un participant au sein de son équipe par glisser-déposer,
     * porté de `participants#reorder`. Appelé en AJAX par
     * `assets/controllers/reorder_controller.js` ; répond sans corps, comme
     * `head :ok` côté Rails.
     */
    #[Route(
        '/{id}/participants/{participantId}/reorder',
        name: 'app_team_participant_reorder',
        requirements: ['id' => '\d+', 'participantId' => '\d+'],
        methods: ['POST'],
    )]
    public function reorderParticipant(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        #[MapEntity(id: 'id')] Team $team,
        #[MapEntity(id: 'participantId')] Participant $participant,
        Request $request,
    ): Response {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertBelongsTo($team->getParticipatingDojo(), $participatingDojo, 'Team does not belong to this participating dojo');

        $this->assertBelongsTo($participant->getTeam(), $team, 'Participant does not belong to this team');

        if (!$this->isCsrfTokenValid('reorder'.$participant->getId(), (string) $request->request->get('_token'))) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->teamingService->reorderParticipant($team, $participant, $request->request->getInt('index'));

        return new Response();
    }

    private function redirectToHostClub(Taikai $taikai, ParticipatingDojo $participatingDojo): RedirectResponse
    {
        return $this->redirectToRoute('app_participating_dojo_edit', [
            'taikaiId' => $taikai->getId(),
            'id' => $participatingDojo->getId(),
        ]);
    }
}

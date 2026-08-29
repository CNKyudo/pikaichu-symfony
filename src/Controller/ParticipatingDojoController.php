<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Form\ParticipatingDojoType;
use App\Security\Voter\ParticipatingDojoVoter;
use App\Security\Voter\TaikaiVoter;
use App\Service\DrawService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clubs hôtes d'un taikai, portés de `participating_dojos_controller`.
 *
 * Comme côté Rails : la création, la modification et la suppression renvoient
 * vers `taikais#edit`, le tirage au sort vers `taikais#show`.
 */
#[Route('/taikais/{taikaiId}/participating-dojos', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class ParticipatingDojoController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/new', name: 'app_participating_dojo_new', methods: ['GET', 'POST'])]
    public function new(#[MapEntity(id: 'taikaiId')] Taikai $taikai, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);

        $participatingDojo = new ParticipatingDojo();
        $taikai->addParticipatingDojo($participatingDojo);

        $form = $this->createForm(ParticipatingDojoType::class, $participatingDojo);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($participatingDojo);
            $this->entityManager->flush();

            $this->addFlash('success', 'participating_dojo.created');

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        // Le club hôte a été rattaché au taikai pour que la validation puisse
        // compter les clubs déjà présents : on le détache si rien n'est écrit.
        if ($form->isSubmitted()) {
            $taikai->removeParticipatingDojo($participatingDojo);
        }

        return $this->render('participating_dojo/new.html.twig', [
            'taikai' => $taikai,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'app_participating_dojo_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): Response {
        $this->assertBelongsTo($participatingDojo->getTaikai(), $taikai, 'Participating dojo does not belong to this taikai');
        $this->denyAccessUnlessGranted(ParticipatingDojoVoter::EDIT, $participatingDojo);

        $form = $this->createForm(ParticipatingDojoType::class, $participatingDojo);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'participating_dojo.updated');

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        return $this->render('participating_dojo/edit.html.twig', [
            'taikai' => $taikai,
            'participatingDojo' => $participatingDojo,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'app_participating_dojo_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): RedirectResponse {
        $this->assertBelongsTo($participatingDojo->getTaikai(), $taikai, 'Participating dojo does not belong to this taikai');
        $this->denyAccessUnlessGranted(ParticipatingDojoVoter::DELETE, $participatingDojo);

        if (!$this->isCsrfTokenValid('delete'.$participatingDojo->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'participating_dojo.delete.invalid_token');

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        // Rails refuse la suppression tant que des membres du staff y sont
        // rattachés, et les nomme dans le message.
        if (!$participatingDojo->getStaffs()->isEmpty()) {
            $names = array_map(
                static fn (\App\Entity\Staff $staff): string => $staff->getDisplayName(),
                $participatingDojo->getStaffs()->toArray(),
            );

            $this->addFlash('error', [
                'key' => 'participating_dojo.delete.still_has_staff',
                'parameters' => ['%names%' => implode(', ', $names)],
            ]);

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        $this->entityManager->remove($participatingDojo);
        $this->entityManager->flush();

        $this->addFlash('success', 'participating_dojo.deleted');

        return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
    }

    /**
     * Tirage au sort de l'ordre de passage du club hôte.
     */
    #[Route('/{id}/draw', name: 'app_participating_dojo_draw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function draw(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] ParticipatingDojo $participatingDojo,
        Request $request,
        DrawService $drawService,
    ): RedirectResponse {
        $this->assertBelongsTo($participatingDojo->getTaikai(), $taikai, 'Participating dojo does not belong to this taikai');
        $this->denyAccessUnlessGranted(ParticipatingDojoVoter::EDIT, $participatingDojo);

        if (!$this->isCsrfTokenValid('draw'.$participatingDojo->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'marking.invalid_token');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        $error = $drawService->draw($participatingDojo);

        if (null !== $error) {
            $this->addFlash('error', $error);
        } else {
            $this->addFlash('success', 'draw.success');
        }

        return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use App\Form\ParticipantType;
use App\Security\Voter\ParticipatingDojoVoter;
use App\Service\ParticipantImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Participants d'un club hôte, portés de `participants_controller`.
 *
 * L'import Excel relève du même contrôleur côté Rails et reste à porter. La
 * saisie au sein d'une équipe suivra avec `teams_controller`.
 */
#[Route(
    '/taikais/{taikaiId}/participating-dojos/{participatingDojoId}/participants',
    requirements: ['taikaiId' => '\d+', 'participatingDojoId' => '\d+'],
)]
#[IsGranted('ROLE_USER')]
final class ParticipantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/new', name: 'app_participant_new', methods: ['GET', 'POST'])]
    public function new(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        Request $request,
    ): Response {
        $this->assertHostClub($taikai, $participatingDojo);

        $participant = new Participant();
        $participatingDojo->addParticipant($participant);

        $form = $this->createForm(ParticipantType::class, $participant, [
            'taikai_form' => $taikai->getForm(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyKyudojinIdentity($participant);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($participant);
            $this->entityManager->flush();

            $this->addFlash('success', 'participant.created');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        // Détaché si rien n'est écrit, pour ne pas fausser le comptage des
        // participants déjà inscrits lors du rendu du formulaire en erreur.
        if ($form->isSubmitted()) {
            $participatingDojo->removeParticipant($participant);
        }

        return $this->render('participant/new.html.twig', [
            'taikai' => $taikai,
            'participatingDojo' => $participatingDojo,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'app_participant_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        #[MapEntity(id: 'id')] Participant $participant,
        Request $request,
    ): Response {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertBelongsToHostClub($participatingDojo, $participant);

        $form = $this->createForm(ParticipantType::class, $participant, [
            'taikai_form' => $taikai->getForm(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyKyudojinIdentity($participant);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'participant.updated');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        return $this->render('participant/edit.html.twig', [
            'taikai' => $taikai,
            'participatingDojo' => $participatingDojo,
            'participant' => $participant,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'app_participant_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        #[MapEntity(id: 'id')] Participant $participant,
        Request $request,
    ): RedirectResponse {
        $this->assertHostClub($taikai, $participatingDojo);
        $this->assertBelongsToHostClub($participatingDojo, $participant);

        if (!$this->isCsrfTokenValid('delete'.$participant->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'participant.delete.invalid_token');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        $this->entityManager->remove($participant);
        $this->entityManager->flush();

        $this->addFlash('success', 'participant.deleted');

        return $this->redirectToHostClub($taikai, $participatingDojo);
    }

    /**
     * Import d'un export « Kyudo - Interface de gestion ».
     */
    #[Route('/import', name: 'app_participant_import', methods: ['POST'])]
    public function import(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
        Request $request,
        ParticipantImporter $importer,
    ): RedirectResponse {
        $this->assertHostClub($taikai, $participatingDojo);

        if (!$this->isCsrfTokenValid('import'.$participatingDojo->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'participant.import.invalid_token');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        $file = $request->files->get('excel');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'participant.import.file_missing');

            return $this->redirectToHostClub($taikai, $participatingDojo);
        }

        $report = $importer->import($participatingDojo, $file->getPathname());

        if ($report->imported > 0) {
            $this->addFlash('success', [
                'key' => 'participant.import.done',
                'parameters' => ['%count%' => $report->imported],
            ]);
        }

        if ([] !== $report->notFound) {
            $this->addFlash('warning', [
                'key' => 'participant.import.not_found',
                'parameters' => [
                    '%names%' => implode(', ', $report->notFound),
                    '%count%' => \count($report->notFound),
                ],
            ]);
        }

        if ([] !== $report->failed) {
            $this->addFlash('error', [
                'key' => 'participant.import.failed',
                'parameters' => [
                    '%names%' => implode(', ', $report->failed),
                    '%count%' => \count($report->failed),
                ],
            ]);
        }

        return $this->redirectToHostClub($taikai, $participatingDojo);
    }

    /**
     * Un licencié sélectionné fait autorité sur l'identité saisie à la main,
     * comme dans `participants_controller`.
     */
    private function applyKyudojinIdentity(Participant $participant): void
    {
        $kyudojin = $participant->getKyudojin();
        if (null === $kyudojin) {
            return;
        }

        $participant->setFirstname($kyudojin->getFirstname())
            ->setLastname($kyudojin->getLastname())
            ->setClub((string) $kyudojin->getFederationClub());
    }

    private function redirectToHostClub(Taikai $taikai, ParticipatingDojo $participatingDojo): RedirectResponse
    {
        return $this->redirectToRoute('app_participating_dojo_edit', [
            'taikaiId' => $taikai->getId(),
            'id' => $participatingDojo->getId(),
        ]);
    }

    private function assertHostClub(Taikai $taikai, ParticipatingDojo $participatingDojo): void
    {
        if ($participatingDojo->getTaikai() !== $taikai) {
            throw $this->createNotFoundException('Participating dojo does not belong to this taikai');
        }

        $this->denyAccessUnlessGranted(ParticipatingDojoVoter::EDIT, $participatingDojo);
    }

    private function assertBelongsToHostClub(ParticipatingDojo $participatingDojo, Participant $participant): void
    {
        if ($participant->getParticipatingDojo() !== $participatingDojo) {
            throw $this->createNotFoundException('Participant does not belong to this participating dojo');
        }
    }
}

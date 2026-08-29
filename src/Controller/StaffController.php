<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Staff;
use App\Entity\Taikai;
use App\Enum\StaffRoleCode;
use App\Form\StaffType;
use App\Security\Voter\TaikaiVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff d'un taikai, porté de `staffs_controller`.
 *
 * Rails n'y applique aucune autorisation Pundit — n'importe quel utilisateur
 * authentifié pourrait s'auto-nommer administrateur d'un taikai qui ne lui
 * appartient pas. C'est une élévation de privilèges plutôt qu'un choix voulu :
 * le portage réserve ces actions à `TAIKAI_EDIT`, comme le reste des écrans
 * d'administration du taikai.
 */
#[Route('/taikais/{taikaiId}/staffs', requirements: ['taikaiId' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class StaffController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/new', name: 'app_staff_new', methods: ['GET', 'POST'])]
    public function new(#[MapEntity(id: 'taikaiId')] Taikai $taikai, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);

        $staff = new Staff();
        $taikai->addStaff($staff);

        $form = $this->createForm(StaffType::class, $staff, [
            'taikai' => $taikai,
            'locale' => $request->getLocale(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyUserIdentity($staff);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($staff);
            $this->entityManager->flush();

            $this->addFlash('success', 'staff.created');

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        // Détaché si rien n'est écrit, pour ne pas fausser les validations qui
        // comptent les membres du staff déjà rattachés au taikai.
        if ($form->isSubmitted()) {
            $taikai->removeStaff($staff);
        }

        return $this->render('staff/new.html.twig', [
            'taikai' => $taikai,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'app_staff_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] Staff $staff,
        Request $request,
    ): Response {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);
        $this->assertBelongsTo($staff->getTaikai(), $taikai, 'Staff does not belong to this taikai');

        $form = $this->createForm(StaffType::class, $staff, [
            'taikai' => $taikai,
            'locale' => $request->getLocale(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyUserIdentity($staff);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'staff.updated');

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        return $this->render('staff/edit.html.twig', [
            'taikai' => $taikai,
            'staff' => $staff,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'app_staff_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'id')] Staff $staff,
        Request $request,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);
        $this->assertBelongsTo($staff->getTaikai(), $taikai, 'Staff does not belong to this taikai');

        if (!$this->isCsrfTokenValid('delete'.$staff->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'staff.delete.invalid_token');

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        // Le taikai doit conserver au moins un administrateur, comme le
        // `before_destroy` du modèle Rails.
        if (StaffRoleCode::TaikaiAdmin === $staff->getRole()?->getCode()
            && 1 === $taikai->getStaffsWithRole(StaffRoleCode::TaikaiAdmin)->count()
        ) {
            $this->addFlash('error', 'staff.at_least_one_admin');

            return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
        }

        $this->entityManager->remove($staff);
        $this->entityManager->flush();

        $this->addFlash('success', 'staff.deleted');

        return $this->redirectToRoute('app_taikai_edit', ['id' => $taikai->getId()]);
    }

    /**
     * Un utilisateur sélectionné fait autorité sur l'identité saisie à la main,
     * comme le `before_validation` du modèle Rails. Appliqué après la liaison du
     * formulaire : `Staff::setUser()` recopie déjà l'identité par commodité pour
     * le code programmatique (fixtures, tests), mais le champ `user` est soumis
     * avant `firstname`/`lastname` dans le formulaire, qui écraseraient sinon la
     * recopie automatique.
     */
    private function applyUserIdentity(Staff $staff): void
    {
        $user = $staff->getUser();
        if (null === $user) {
            return;
        }

        $staff->setFirstname($user->getFirstname())->setLastname($user->getLastname());
    }
}

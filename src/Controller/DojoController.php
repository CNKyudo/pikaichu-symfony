<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dojo;
use App\Form\DojoType;
use App\Repository\DojoRepository;
use App\Repository\ParticipatingDojoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Référentiel des clubs, porté de `dojos_controller`.
 *
 * Comme côté Rails, il n'y a pas d'écran de détail : la liste renvoie
 * directement vers le formulaire de modification.
 */
#[Route('/dojos')]
#[IsGranted('ROLE_USER')]
final class DojoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_dojo_index', methods: ['GET'])]
    public function index(
        Request $request,
        DojoRepository $dojoRepository,
        PaginatorInterface $paginator,
    ): Response {
        $pagination = $paginator->paginate(
            $dojoRepository->createListQueryBuilder(),
            $request->query->getInt('page', 1),
            25,
        );

        return $this->render('dojo/index.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_dojo_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dojo = new Dojo();
        $form = $this->createForm(DojoType::class, $dojo);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($dojo);
            $this->entityManager->flush();

            $this->addFlash('success', 'dojo.created');

            return $this->redirectToRoute('app_dojo_index');
        }

        return $this->render('dojo/new.html.twig', [
            'dojo' => $dojo,
            'form' => $form,
        ], new Response(null, $this->formStatus($form->isSubmitted() && !$form->isValid())));
    }

    #[Route('/{id}/edit', name: 'app_dojo_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Dojo $dojo): Response
    {
        $form = $this->createForm(DojoType::class, $dojo);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'dojo.updated');

            return $this->redirectToRoute('app_dojo_index');
        }

        return $this->render('dojo/edit.html.twig', [
            'dojo' => $dojo,
            'form' => $form,
        ], new Response(null, $this->formStatus($form->isSubmitted() && !$form->isValid())));
    }

    #[Route('/{id}', name: 'app_dojo_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        Dojo $dojo,
        ParticipatingDojoRepository $participatingDojoRepository,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('delete'.$dojo->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'dojo.delete.invalid_token');

            return $this->redirectToRoute('app_dojo_index');
        }

        // `dependent: :restrict_with_error` côté Rails : on refuse la suppression
        // plutôt que de laisser la contrainte de clé étrangère remonter.
        if ($participatingDojoRepository->existsForDojo($dojo)) {
            $this->addFlash('error', 'dojo.delete.still_used');

            return $this->redirectToRoute('app_dojo_index');
        }

        $this->entityManager->remove($dojo);
        $this->entityManager->flush();

        $this->addFlash('success', 'dojo.deleted');

        return $this->redirectToRoute('app_dojo_index');
    }

    /** Rails répond 422 sur formulaire invalide ; on conserve ce code. */
    private function formStatus(bool $invalid): int
    {
        return $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
    }
}

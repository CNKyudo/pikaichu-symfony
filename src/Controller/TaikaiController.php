<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Staff;
use App\Entity\Taikai;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiState;
use App\Exception\TransitionNotAllowedException;
use App\Form\TaikaiType;
use App\Repository\StaffRoleRepository;
use App\Repository\TaikaiRepository;
use App\Service\TaikaiStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des taikai : liste, création, vue d'ensemble et avancement des étapes.
 */
#[Route('/taikais')]
#[IsGranted('ROLE_USER')]
final class TaikaiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaikaiStateMachine $stateMachine,
    ) {
    }

    #[Route('', name: 'app_taikai_index', methods: ['GET'])]
    public function index(
        Request $request,
        TaikaiRepository $taikaiRepository,
        PaginatorInterface $paginator,
    ): Response {
        $pagination = $paginator->paginate(
            $taikaiRepository->createListQueryBuilder(),
            $request->query->getInt('page', 1),
            10,
        );

        return $this->render('taikai/index.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_taikai_new', methods: ['GET', 'POST'])]
    public function new(Request $request, StaffRoleRepository $staffRoleRepository): Response
    {
        $taikai = new Taikai();
        $form = $this->createForm(TaikaiType::class, $taikai);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();

            // Le créateur devient administrateur du taikai, comme le
            // `after_create` du modèle Rails.
            $staff = new Staff();
            $staff->setRole($staffRoleRepository->getByCode(StaffRoleCode::TaikaiAdmin))
                ->setUser($user);
            $taikai->addStaff($staff);

            $this->entityManager->persist($taikai);
            $this->entityManager->flush();

            $this->addFlash('success', 'taikai.created');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        return $this->render('taikai/new.html.twig', [
            'taikai' => $taikai,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_taikai_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Taikai $taikai): Response
    {
        return $this->render('taikai/show.html.twig', [
            'taikai' => $taikai,
            'allowed_transitions' => $this->stateMachine->getAllowedTransitions($taikai),
            'states' => TaikaiState::cases(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_taikai_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Taikai $taikai): Response
    {
        $this->denyAccessUnlessGranted('TAIKAI_EDIT', $taikai);

        $form = $this->createForm(TaikaiType::class, $taikai);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'taikai.updated');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        return $this->render('taikai/edit.html.twig', [
            'taikai' => $taikai,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_taikai_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Taikai $taikai): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted('TAIKAI_EDIT', $taikai);

        if ($this->isCsrfTokenValid('delete'.$taikai->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($taikai);
            $this->entityManager->flush();
            $this->addFlash('success', 'taikai.deleted');
        }

        return $this->redirectToRoute('app_taikai_index');
    }

    /**
     * Fait avancer ou reculer le taikai d'une étape.
     */
    #[Route('/{id}/transition', name: 'app_taikai_transition', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transition(Request $request, Taikai $taikai): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted('TAIKAI_EDIT', $taikai);

        if (!$this->isCsrfTokenValid('transition'.$taikai->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'taikai.transition.invalid_token');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        $target = TaikaiState::tryFrom((string) $request->request->get('to_state'));
        if (!$target instanceof TaikaiState) {
            $this->addFlash('error', 'taikai.transition.unknown_state');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->stateMachine->transitionTo($taikai, $target, $user);
            $this->addFlash('success', 'taikai.transition.success');
        } catch (TransitionNotAllowedException $transitionNotAllowedException) {
            $this->addFlash('error', $transitionNotAllowedException->reason);
        }

        return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
    }
}

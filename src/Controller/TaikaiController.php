<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Staff;
use App\Entity\Taikai;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiState;
use App\Exception\TaikaiGenerationException;
use App\Exception\TransitionNotAllowedException;
use App\Form\TaikaiType;
use App\Repository\StaffRoleRepository;
use App\Repository\TaikaiRepository;
use App\Security\Voter\TaikaiVoter;
use App\Service\MatchService;
use App\Service\TaikaiExportService;
use App\Service\TaikaiGenerationService;
use App\Service\TaikaiStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly TaikaiGenerationService $generationService,
        private readonly TaikaiExportService $exportService,
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
        ]);
    }

    /**
     * Export Excel du taikai, porté de `taikais#export`.
     */
    #[Route('/{id}/export', name: 'app_taikai_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(Taikai $taikai): StreamedResponse
    {
        $spreadsheet = $this->exportService->export($taikai);
        $writer = new Xlsx($spreadsheet);

        $response = new StreamedResponse(static function () use ($writer): void {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('Taikai - %s.xlsx', $taikai->getShortname() ?? $taikai->getId()),
        ));

        return $response;
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
    public function delete(Request $request, Taikai $taikai): RedirectResponse
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
    public function transition(Request $request, Taikai $taikai): RedirectResponse
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

    /**
     * Génère la 2ᵉ partie (tableau à matchs) d'un taikai 2-en-1 terminé,
     * porté de `taikais#generate`.
     */
    #[Route('/{id}/generate', name: 'app_taikai_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(Request $request, Taikai $taikai): RedirectResponse
    {
        $this->denyAccessUnlessGranted(TaikaiVoter::EDIT, $taikai);

        if (!$this->isCsrfTokenValid('generate'.$taikai->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'taikai.generate.invalid_token');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        if (TaikaiForm::TwoInOne !== $taikai->getForm()) {
            $this->addFlash('error', 'taikai.generate.wrong_form');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        $bracketSize = $request->request->getInt('bracket_size');
        if (!\in_array($bracketSize, MatchService::BRACKET_SIZES, true)) {
            $this->addFlash('error', 'taikai.generate.wrong_bracket_size');

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $newTaikai = $this->generationService->generateFromTwoInOne($taikai, $user, $bracketSize);

            return $this->redirectToRoute('app_taikai_show', ['id' => $newTaikai->getId()]);
        } catch (TaikaiGenerationException $taikaiGenerationException) {
            $this->addFlash('error', [
                'key' => $taikaiGenerationException->translationKey,
                'parameters' => $taikaiGenerationException->parameters,
            ]);

            return $this->redirectToRoute('app_taikai_show', ['id' => $taikai->getId()]);
        }
    }
}

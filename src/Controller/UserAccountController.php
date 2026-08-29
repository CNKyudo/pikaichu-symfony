<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\UserAccountType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modification de son propre compte, portée de `users_controller`.
 *
 * Voir `UserAccountType` : Rails ne rend pas cette action réellement
 * accessible, le portage lui donne un écran.
 */
#[Route('/account')]
#[IsGranted('ROLE_USER')]
final class UserAccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/edit', name: 'app_account_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(UserAccountType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'user.account.updated');

            return $this->redirectToRoute('app_home');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

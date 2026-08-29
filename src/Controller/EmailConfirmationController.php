<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ResendConfirmationFormType;
use App\Service\EmailConfirmationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirmation d'adresse courriel, absente côté Rails (voir
 * `EmailConfirmationService`).
 */
final class EmailConfirmationController extends AbstractController
{
    public function __construct(
        private readonly EmailConfirmationService $emailConfirmationService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Renvoie le courriel de confirmation. Répond toujours par le même écran
     * générique, que l'adresse existe ou non, comme la réinitialisation de
     * mot de passe.
     *
     * Déclarée avant `confirm()` : sans cela, `/confirm-email/resend`
     * matcherait `/confirm-email/{token}` avec `token = 'resend'`, la route
     * générique étant enregistrée en premier.
     */
    #[Route('/confirm-email/resend', name: 'app_email_confirmation_resend', methods: ['GET', 'POST'])]
    public function resend(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResendConfirmationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $emailAddress */
            $emailAddress = $form->get('emailAddress')->getData();

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailAddress' => $emailAddress]);
            if ($user instanceof User && !$user->isConfirmed()) {
                $this->emailConfirmationService->sendConfirmation($user, $mailer);
            }

            $this->addFlash('success', 'email_confirmation.resent');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('email_confirmation/resend.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/confirm-email/{token}', name: 'app_email_confirmation_confirm', methods: ['GET'])]
    public function confirm(string $token): RedirectResponse
    {
        $user = $this->emailConfirmationService->confirm($token);

        if (null === $user) {
            $this->addFlash('error', 'email_confirmation.invalid_token');

            return $this->redirectToRoute('app_email_confirmation_resend');
        }

        $this->addFlash('success', 'email_confirmation.confirmed');

        return $this->redirectToRoute('app_login');
    }
}

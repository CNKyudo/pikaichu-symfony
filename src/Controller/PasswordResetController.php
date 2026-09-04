<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Réinitialisation de mot de passe, portée de `passwords_controller` (Rails 8).
 *
 * Rails génère un jeton signé en mémoire (`signed_id`) ; ici le jeton est un
 * jeton à usage unique persisté par `symfonycasts/reset-password-bundle`, qui
 * offre en plus la protection contre l'énumération de comptes (l'écran de
 * confirmation est identique, que l'adresse existe ou non) déjà présente côté
 * Rails, ainsi qu'un anti-abus par utilisateur (voir `config/packages/reset_password.yaml`).
 */
final class PasswordResetController extends AbstractController
{
    use ResetPasswordControllerTrait;

    private const string SENDER = 'no-reply@kyudo.fr';

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/reset-password', name: 'app_password_reset_request', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $emailAddress */
            $emailAddress = $form->get('emailAddress')->getData();

            return $this->sendResetEmail($emailAddress, $mailer);
        }

        return $this->render('password_reset/request.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/reset-password/check-email', name: 'app_password_reset_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // Un jeton factice est généré si l'utilisateur n'existe pas, pour ne
        // jamais révéler si une adresse email est enregistrée ou non.
        if (!($resetToken = $this->getTokenObjectFromSession()) instanceof ResetPasswordToken) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('password_reset/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'app_password_reset', methods: ['GET', 'POST'])]
    public function reset(Request $request, ?string $token = null): Response
    {
        if (null !== $token) {
            // Le jeton est déplacé dans la session et retiré de l'URL, pour
            // éviter qu'il ne fuite via un en-tête Referer.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_password_reset');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->addFlash('error', 'password_reset.invalid_token');

            return $this->redirectToRoute('app_password_reset_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $this->entityManager->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'password_reset.updated');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('password_reset/reset.html.twig', [
            'form' => $form,
        ]);
    }

    private function sendResetEmail(string $emailAddress, MailerInterface $mailer): RedirectResponse
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailAddress' => $emailAddress]);

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_password_reset_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Jeton déjà demandé récemment (anti-abus) : on affiche quand même
            // l'écran de confirmation, sans révéler la raison.
            return $this->redirectToRoute('app_password_reset_check_email');
        }

        $email = new TemplatedEmail()
            ->from(new Address(self::SENDER, 'Pikaichu'))
            ->to($user->getEmailAddress() ?? '')
            ->subject($this->translator->trans('password_reset.email.subject', locale: $user->getLocale()))
            ->htmlTemplate('password_reset/email.html.twig')
            ->context([
                'user' => $user,
                'resetToken' => $resetToken,
                'locale' => $user->getLocale(),
            ]);

        $mailer->send($email);

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_password_reset_check_email');
    }
}

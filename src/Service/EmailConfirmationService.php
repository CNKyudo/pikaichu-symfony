<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confirmation d'adresse courriel à l'inscription.
 *
 * N'existe pas côté Rails : `registrations_controller#create` confirme le
 * compte immédiatement, avec le commentaire « Auto-confirm for now, can add
 * email confirmation later » — les colonnes (`confirmed_at`,
 * `confirmation_token`, `confirmation_sent_at`) sont là, mais rien ne les
 * utilise. Ce service construit la fonctionnalité, sur le même modèle que
 * `PasswordResetController` (jeton envoyé par courriel, Mailpit en local).
 */
final readonly class EmailConfirmationService
{
    private const string SENDER = 'no-reply@kyudo.fr';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Génère un nouveau jeton et envoie le courriel de confirmation. Peut être
     * appelé à l'inscription comme pour un renvoi.
     */
    public function sendConfirmation(User $user, MailerInterface $mailer): void
    {
        $token = bin2hex(random_bytes(32));
        $user->setConfirmationToken($token)->setConfirmationSentAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $email = new TemplatedEmail()
            ->from(new Address(self::SENDER, 'Pikaichu'))
            ->to((string) $user->getEmailAddress())
            ->subject($this->translator->trans('email_confirmation.email.subject', locale: $user->getLocale()))
            ->htmlTemplate('email_confirmation/email.html.twig')
            ->context([
                'user' => $user,
                'token' => $token,
                'locale' => $user->getLocale(),
            ]);

        $mailer->send($email);
    }

    /**
     * Confirme le compte associé au jeton, ou `null` si le jeton est invalide.
     * Le jeton est à usage unique : il est retiré après confirmation.
     */
    public function confirm(string $token): ?User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['confirmationToken' => $token]);
        if (!$user instanceof User) {
            return null;
        }

        $user->setConfirmedAt(new \DateTimeImmutable())->setConfirmationToken(null);
        $this->entityManager->flush();

        return $user;
    }
}

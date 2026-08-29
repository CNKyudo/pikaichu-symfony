<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de demande de réinitialisation de mot de passe (`passwords#new`).
 *
 * @extends AbstractType<array{emailAddress?: string}>
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('emailAddress', EmailType::class, [
            'label' => 'user.email_address',
            'mapped' => false,
            'constraints' => [
                new Assert\NotBlank(message: 'password_reset.email.blank'),
                new Assert\Email(),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }
}

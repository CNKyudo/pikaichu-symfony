<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de saisie du nouveau mot de passe (`passwords#edit`).
 *
 * @extends AbstractType<array{plainPassword?: string}>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'first_options' => ['label' => 'user.password'],
            'second_options' => ['label' => 'user.password_confirmation'],
            'invalid_message' => 'user.password.mismatch',
            'constraints' => [
                new Assert\NotBlank(message: 'user.password.blank'),
                // Borne haute imposée par bcrypt, comme la validation Rails.
                new Assert\Length(min: 8, max: 72, minMessage: 'user.password.too_short'),
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

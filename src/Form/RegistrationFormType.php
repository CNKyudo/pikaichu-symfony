<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire d'inscription.
 *
 * @extends AbstractType<User>
 */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emailAddress', EmailType::class, [
                'label' => 'user.email_address',
            ])
            ->add('firstname', TextType::class, [
                'label' => 'user.firstname',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'user.lastname',
            ])
            ->add('plainPassword', RepeatedType::class, [
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
            'data_class' => User::class,
            'translation_domain' => 'messages',
        ]);
    }
}

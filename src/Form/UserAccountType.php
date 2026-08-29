<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Modification de son propre compte, portée de `users_controller#update`.
 *
 * Rails ne fournit ni vue ni formulaire pour cette action (aucun
 * `app/views/users/edit.html.erb`, aucun lien dans la navigation) : elle est
 * inaccessible en pratique et plante même sur une erreur de validation
 * (`render :edit` sans gabarit). Le portage reprend l'intention — modifier son
 * identité et sa langue — avec un écran réellement accessible.
 *
 * @extends AbstractType<User>
 */
final class UserAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, [
                'label' => 'user.firstname',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'user.lastname',
            ])
            ->add('emailAddress', EmailType::class, [
                'label' => 'user.email_address',
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'user.locale',
                'choices' => [
                    'user.locale.fr' => 'fr',
                    'user.locale.en' => 'en',
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

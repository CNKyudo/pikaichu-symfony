<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Team;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie manuelle d'une équipe, porté de `teams_controller`.
 *
 * @extends AbstractType<Team>
 */
final class TeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('index', IntegerType::class, [
                'label' => 'team.index',
                'help' => 'team.index.help',
                'required' => false,
            ])
            ->add('shortname', TextType::class, [
                'label' => 'team.shortname',
            ])
            ->add('mixed', CheckboxType::class, [
                'label' => 'team.mixed',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Team::class,
            'translation_domain' => 'messages',
        ]);
    }
}

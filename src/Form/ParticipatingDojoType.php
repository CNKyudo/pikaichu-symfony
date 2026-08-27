<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Dojo;
use App\Entity\ParticipatingDojo;
use App\Repository\DojoRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'ajout et de modification d'un club hôte.
 *
 * Rails choisit le club via une autocomplétion adossée à `search#dojos` ; ce
 * point n'étant pas encore porté, on présente ici la liste déroulante complète
 * du référentiel, qui expose exactement les mêmes clubs.
 *
 * @extends AbstractType<ParticipatingDojo>
 */
final class ParticipatingDojoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dojo', EntityType::class, [
                'label' => 'participating_dojo.dojo',
                'class' => Dojo::class,
                'choice_label' => 'shortname',
                'placeholder' => '',
                'query_builder' => static fn (DojoRepository $repository): \Doctrine\ORM\QueryBuilder => $repository->createListQueryBuilder(),
                // Le club ne se change plus une fois le club hôte créé : Rails
                // fige de fait ce lien via la contrainte d'unicité taikai/dojo.
                'disabled' => null !== $options['data']?->getId(),
            ])
            ->add('displayName', TextType::class, [
                'label' => 'participating_dojo.display_name',
                'help' => 'participating_dojo.display_name.help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParticipatingDojo::class,
            'translation_domain' => 'messages',
        ]);
    }
}

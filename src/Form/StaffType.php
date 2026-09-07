<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Form\Autocomplete\StaffUserAutocompleteType;
use App\Repository\ParticipatingDojoRepository;
use App\Repository\StaffRoleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Formulaire de création et de modification d'un membre du staff.
 *
 * Comme côté Rails, sélectionner un utilisateur écrase l'identité saisie à la
 * main (voir `identity-autofill` côté JS) et le compte se recherche par
 * autocomplétion (`search#users` côté Rails) plutôt que via un <select> listant
 * tous les comptes, impraticable au-delà d'une centaine d'utilisateurs.
 *
 * @extends AbstractType<Staff>
 */
final class StaffType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Taikai $taikai */
        $taikai = $options['taikai'];
        /** @var string $locale */
        $locale = $options['locale'];
        /** @var Staff $staff */
        $staff = $builder->getData();

        $builder
            ->add('user', StaffUserAutocompleteType::class, [
                'label' => 'staff.user',
                'help' => 'staff.user.help',
                'placeholder' => '',
                'required' => false,
                'autocomplete_url' => $this->urlGenerator->generate('app_search_staff_users', array_filter([
                    'taikaiId' => $taikai->getId(),
                    'staffId' => $staff->getId(),
                ])),
            ])
            ->add('firstname', TextType::class, [
                'label' => 'staff.firstname',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'staff.lastname',
            ])
            ->add('role', EntityType::class, [
                'label' => 'staff.role',
                'class' => StaffRole::class,
                'choice_label' => static fn (StaffRole $role): string => $role->translatedLabel($locale),
                'query_builder' => static fn (StaffRoleRepository $repository): \Doctrine\ORM\QueryBuilder => $repository->createQueryBuilder('r')
                    ->orderBy('r.id', 'ASC'),
            ])
            ->add('participatingDojo', EntityType::class, [
                'label' => 'staff.participating_dojo',
                'class' => ParticipatingDojo::class,
                'choice_label' => 'displayName',
                'placeholder' => '',
                'required' => false,
                'query_builder' => static fn (ParticipatingDojoRepository $repository): \Doctrine\ORM\QueryBuilder => $repository->createQueryBuilder('pd')
                    ->andWhere('pd.taikai = :taikai')
                    ->setParameter('taikai', $taikai)
                    ->orderBy('pd.displayName', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Staff::class,
            'translation_domain' => 'messages',
        ]);

        $resolver->setRequired(['taikai', 'locale']);
        $resolver->setAllowedTypes('taikai', Taikai::class);
        $resolver->setAllowedTypes('locale', 'string');
    }
}

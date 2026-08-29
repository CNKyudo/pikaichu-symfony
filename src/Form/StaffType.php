<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\User;
use App\Repository\ParticipatingDojoRepository;
use App\Repository\StaffRoleRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création et de modification d'un membre du staff.
 *
 * Rails recherche l'utilisateur par autocomplétion (`search#users`) ; ce point
 * n'étant pas encore porté, on présente la liste complète des comptes. Comme
 * côté Rails, sélectionner un utilisateur écrase l'identité saisie à la main.
 *
 * @extends AbstractType<Staff>
 */
final class StaffType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Taikai $taikai */
        $taikai = $options['taikai'];
        /** @var string $locale */
        $locale = $options['locale'];

        $builder
            ->add('user', EntityType::class, [
                'label' => 'staff.user',
                'help' => 'staff.user.help',
                'class' => User::class,
                'choice_label' => 'displayName',
                'placeholder' => '',
                'required' => false,
                'query_builder' => static fn (UserRepository $repository): \Doctrine\ORM\QueryBuilder => $repository->createQueryBuilder('u')
                    ->orderBy('u.lastname', 'ASC')
                    ->addOrderBy('u.firstname', 'ASC'),
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

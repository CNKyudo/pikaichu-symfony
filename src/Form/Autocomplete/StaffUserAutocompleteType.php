<?php

declare(strict_types=1);

namespace App\Form\Autocomplete;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Champ « Compte utilisateur » du staff, en recherche AJAX plutôt qu'un
 * <select> listant tous les comptes (impraticable au-delà d'une centaine
 * d'utilisateurs), comme l'autocomplétion `search#users` côté Rails.
 *
 * L'URL de recherche pointe vers l'endpoint déjà exposé et testé par
 * {@see \App\Controller\SearchController::staffUsers()} : ce type ne fait que
 * brancher le widget TomSelect dessus, il ne duplique pas la logique
 * d'exclusion des comptes déjà membres du staff.
 *
 * @extends AbstractType<User>
 */
final class StaffUserAutocompleteType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => User::class,
            'choice_label' => 'displayName',
            'query_builder' => static fn (UserRepository $repository): QueryBuilder => $repository->createQueryBuilder('u')
                ->orderBy('u.lastname', 'ASC')
                ->addOrderBy('u.firstname', 'ASC'),
            'tom_select_options' => [
                'valueField' => 'id',
                'labelField' => 'label',
                'searchField' => ['label'],
            ],
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}

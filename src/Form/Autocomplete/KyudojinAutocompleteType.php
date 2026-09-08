<?php

declare(strict_types=1);

namespace App\Form\Autocomplete;

use App\Entity\Kyudojin;
use App\Repository\KyudojinRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Champ « Licencié » du participant, en recherche AJAX plutôt qu'un <select>
 * listant tout le référentiel fédéral, comme {@see StaffUserAutocompleteType}
 * pour le staff.
 *
 * L'URL de recherche pointe vers {@see \App\Controller\SearchController::kyudojins()}.
 *
 * @extends AbstractType<Kyudojin>
 */
final class KyudojinAutocompleteType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Kyudojin::class,
            'choice_label' => 'displayName',
            'query_builder' => static fn (KyudojinRepository $repository): QueryBuilder => $repository->createQueryBuilder('k')
                ->orderBy('k.lastname', 'ASC')
                ->addOrderBy('k.firstname', 'ASC'),
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

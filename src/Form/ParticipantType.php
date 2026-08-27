<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Kyudojin;
use App\Entity\Participant;
use App\Enum\TaikaiForm;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie manuelle d'un participant.
 *
 * Rails recherche le licencié par autocomplétion (`search#kyudojins`) ; ce point
 * n'étant pas encore porté, on présente la liste des licenciés connus. Comme
 * côté Rails, sélectionner un licencié écrase l'identité saisie à la main.
 *
 * @extends AbstractType<Participant>
 */
final class ParticipantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kyudojin', EntityType::class, [
                'label' => 'participant.kyudojin',
                'help' => 'participant.kyudojin.help',
                'class' => Kyudojin::class,
                'choice_label' => 'displayName',
                'placeholder' => '',
                'required' => false,
            ])
            ->add('firstname', TextType::class, [
                'label' => 'participant.firstname',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'participant.lastname',
            ])
            ->add('club', TextType::class, [
                'label' => 'participant.club',
                'required' => false,
                'empty_data' => '',
            ]);

        // L'ordre de passage ne se saisit qu'en tournoi individuel : ailleurs il
        // découle de la composition des équipes.
        if (TaikaiForm::Individual === $options['taikai_form']) {
            $builder->add('index', IntegerType::class, [
                'label' => 'participant.index',
                'help' => 'participant.index.help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Participant::class,
            'translation_domain' => 'messages',
        ]);

        $resolver->setRequired('taikai_form');
        $resolver->setAllowedTypes('taikai_form', [TaikaiForm::class, 'null']);
    }
}

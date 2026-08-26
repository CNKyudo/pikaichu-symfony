<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Taikai;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création et de modification d'un taikai.
 *
 * @extends AbstractType<Taikai>
 */
final class TaikaiType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('shortname', TextType::class, [
                'label' => 'taikai.shortname',
                'help' => 'taikai.shortname.help',
            ])
            ->add('name', TextType::class, [
                'label' => 'taikai.name',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'taikai.description',
                'required' => false,
            ])
            ->add('startDate', DateType::class, [
                'label' => 'taikai.start_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'taikai.end_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('scoring', EnumType::class, [
                'label' => 'taikai.scoring',
                'class' => TaikaiScoring::class,
                'choice_label' => static fn (TaikaiScoring $scoring): string => $scoring->label(),
            ])
            ->add('form', EnumType::class, [
                'label' => 'taikai.form',
                'class' => TaikaiForm::class,
                'choice_label' => static fn (TaikaiForm $form): string => $form->label(),
            ])
            ->add('totalNumArrows', IntegerType::class, [
                'label' => 'taikai.total_num_arrows',
                'help' => 'taikai.total_num_arrows.help',
            ])
            ->add('numTargets', ChoiceType::class, [
                'label' => 'taikai.num_targets',
                'choices' => array_combine(Taikai::NUM_TARGETS, Taikai::NUM_TARGETS),
            ])
            ->add('tachiSize', ChoiceType::class, [
                'label' => 'taikai.tachi_size',
                'choices' => array_combine(Taikai::TACHI_SIZES, Taikai::TACHI_SIZES),
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'taikai.category',
                'required' => false,
                'placeholder' => '',
                'choices' => array_combine(Taikai::CATEGORY_VALUES, Taikai::CATEGORY_VALUES),
            ])
            ->add('distributed', CheckboxType::class, [
                'label' => 'taikai.distributed',
                'help' => 'taikai.distributed.help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Taikai::class,
            'translation_domain' => 'messages',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Result;
use App\Enum\ResultStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de rectification d'une flèche, porté de `rectification_controller`.
 *
 * Volontairement non lié à l'entité `Result` par `data_class` : la mutation
 * doit passer par `Result::overrideStatus()`/`overrideValue()`, qui posent le
 * drapeau `overriden`, et non par les setters ordinaires qu'un binding direct
 * invoquerait. Les données restent donc un simple tableau, lu et appliqué par
 * `MarkingService::rectify()` dans le contrôleur.
 *
 * @extends AbstractType<array{status?: ResultStatus, value?: int|null}>
 */
final class ResultRectificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['enteki']) {
            $builder->add('value', ChoiceType::class, [
                'label' => 'rectification.value',
                'choices' => array_combine(
                    array_map(strval(...), Result::ENTEKI_VALUES),
                    Result::ENTEKI_VALUES,
                ),
            ]);

            return;
        }

        // Rails exclut délibérément « incertain » des choix de rectification :
        // seuls les statuts définitifs peuvent être forcés.
        $builder->add('status', EnumType::class, [
            'label' => 'rectification.status',
            'class' => ResultStatus::class,
            'choices' => [ResultStatus::Hit, ResultStatus::Miss],
            'choice_label' => static fn (ResultStatus $status): string => 'marking.status.'.$status->value,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'messages']);
        $resolver->setRequired('enteki');
        $resolver->setAllowedTypes('enteki', 'bool');
    }
}

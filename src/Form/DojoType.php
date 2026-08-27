<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Dojo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création et de modification d'un club.
 *
 * @extends AbstractType<Dojo>
 */
final class DojoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('shortname', TextType::class, [
                'label' => 'dojo.shortname',
                'help' => 'dojo.shortname.help',
            ])
            ->add('name', TextType::class, [
                'label' => 'dojo.name',
            ])
            ->add('city', TextType::class, [
                'label' => 'dojo.city',
                'required' => false,
            ])
            // Rails puise la liste dans ISO3166 ; `CountryType` s'appuie sur
            // symfony/intl et produit les mêmes codes alpha-2.
            ->add('countryCode', CountryType::class, [
                'label' => 'dojo.country_code',
                'placeholder' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Dojo::class,
            'translation_domain' => 'messages',
        ]);
    }
}

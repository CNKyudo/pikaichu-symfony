<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\StaffRole;
use App\Enum\StaffRoleCode;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Rôles du staff. Données de référence indispensables au fonctionnement de
 * l'application, reprises telles quelles de `db/seeds.rb` côté Rails.
 */
final class StaffRoleFixtures extends Fixture
{
    /**
     * Libellés officiels, par code de rôle.
     *
     * @var array<string, array{fr: string, en: string}>
     */
    private const array LABELS = [
        'taikai_admin' => ['fr' => 'Administrateur', 'en' => 'Administrator'],
        'dojo_admin' => ['fr' => 'Administrateur de club', 'en' => 'Dojo Administrator'],
        'chairman' => ['fr' => 'Directeur du tournoi', 'en' => 'Chairman'],
        'marking_referee' => ['fr' => 'Enregistreur', 'en' => 'Marking Referee'],
        'shajo_referee' => ['fr' => 'Juge de Shajo', 'en' => 'Shajo Referee'],
        'yatori' => ['fr' => 'Yatori', 'en' => 'Yatori'],
        'target_referee' => ['fr' => 'Juge de Cible', 'en' => 'Target Referee'],
        'operations_chairman' => ['fr' => 'Responsable Logistique', 'en' => 'Operations Chairman'],
    ];

    /** Préfixe des références réutilisables par les autres fixtures. */
    public const string REFERENCE_PREFIX = 'staff-role-';

    public function load(ObjectManager $manager): void
    {
        foreach (StaffRoleCode::cases() as $code) {
            $labels = self::LABELS[$code->value];

            $role = new StaffRole();
            $role->setCode($code)
                ->setLabel($labels)
                ->setDescription($labels);

            $manager->persist($role);
            $this->addReference(self::REFERENCE_PREFIX.$code->value, $role);
        }

        $manager->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Dojo;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use App\Entity\Staff;
use App\Entity\StaffRole;
use App\Entity\Taikai;
use App\Entity\User;
use App\Enum\StaffRoleCode;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données de démonstration : un compte administrateur, deux clubs et un
 * taikai individuel prêt à être joué de bout en bout.
 */
final class AppFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [StaffRoleFixtures::class];
    }

    /** @return list<string> */
    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        $admin = $this->createUser('admin@pikaichu.test', 'Admin', 'Pikaichu', true);
        $manager->persist($admin);

        $referee = $this->createUser('juge@pikaichu.test', 'Juge', 'De Cible', false);
        $manager->persist($referee);

        $dojoA = $this->createDojo('KTLG', 'Kyudo Traditionnel Loire et Goulaine', 'Basse-Goulaine');
        $dojoB = $this->createDojo('AKVM', 'Association Kyudo Val Maubuée', 'Noisiel');
        $manager->persist($dojoA);
        $manager->persist($dojoB);

        $taikai = new Taikai();
        $taikai->setShortname('DTN-demo')
            ->setName('Taikai de démonstration')
            ->setDescription("Jeu de données d'exemple pour le développement.")
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setForm(TaikaiForm::Individual)
            ->setScoring(TaikaiScoring::Kinteki)
            ->setTotalNumArrows(12)
            ->setNumTargets(6)
            ->setTachiSize(3)
            ->setDistributed(false)
            ->setCategory('A');
        $manager->persist($taikai);

        // Le staff minimal exigé pour atteindre l'étape « Marquage ».
        // Liste de paires plutôt qu'un tableau indexé : un enum ne peut pas
        // servir de clé de tableau en PHP.
        $staffing = [
            [StaffRoleCode::TaikaiAdmin, $admin],
            [StaffRoleCode::Chairman, $admin],
            [StaffRoleCode::ShajoReferee, $admin],
            [StaffRoleCode::TargetReferee, $referee],
        ];

        foreach ($staffing as [$code, $user]) {
            $manager->persist($this->createStaff($taikai, $code, $user));
        }

        $participatingDojo = new ParticipatingDojo();
        $participatingDojo->setDojo($dojoA)->setDisplayName('KTLG');
        $taikai->addParticipatingDojo($participatingDojo);
        $manager->persist($participatingDojo);

        $archers = [
            ['Akira', 'Tanaka'],
            ['Yuki', 'Sato'],
            ['Hana', 'Suzuki'],
            ['Kenji', 'Takahashi'],
            ['Mei', 'Watanabe'],
            ['Ren', 'Ito'],
        ];

        foreach ($archers as [$firstname, $lastname]) {
            $participant = new Participant();
            $participant->setFirstname($firstname)
                ->setLastname($lastname)
                ->setClub('KTLG');

            $participatingDojo->addParticipant($participant);
            $manager->persist($participant);
        }

        $manager->flush();
    }

    private function createUser(string $email, string $firstname, string $lastname, bool $admin): User
    {
        $user = new User();
        $user->setEmailAddress($email)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setAdmin($admin)
            ->setLocale('fr')
            ->setConfirmedAt(new \DateTimeImmutable());

        $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));

        return $user;
    }

    private function createDojo(string $shortname, string $name, string $city): Dojo
    {
        return new Dojo()
            ->setShortname($shortname)
            ->setName($name)
            ->setCity($city)
            ->setCountryCode('FR');
    }

    private function createStaff(Taikai $taikai, StaffRoleCode $code, User $user): Staff
    {
        /** @var StaffRole $role */
        $role = $this->getReference(StaffRoleFixtures::REFERENCE_PREFIX.$code->value, StaffRole::class);

        $staff = new Staff();
        $staff->setRole($role)->setUser($user);
        $taikai->addStaff($staff);

        return $staff;
    }
}

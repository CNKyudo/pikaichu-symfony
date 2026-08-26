<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Codes des rôles du staff, repris des seeds Rails (db/seeds.rb).
 * Les libellés traduits vivent en base dans `staff_roles` (colonnes JSON label/description).
 */
enum StaffRoleCode: string
{
    case TaikaiAdmin = 'taikai_admin';
    case DojoAdmin = 'dojo_admin';
    case Chairman = 'chairman';
    case MarkingReferee = 'marking_referee';
    case ShajoReferee = 'shajo_referee';
    case Yatori = 'yatori';
    case TargetReferee = 'target_referee';
    case OperationsChairman = 'operations_chairman';

    /**
     * Rôles obligatoires pour pouvoir passer à l'étape « Marquage ».
     *
     * @return list<self>
     */
    public static function requiredForMarking(): array
    {
        return [self::Chairman, self::ShajoReferee, self::TargetReferee];
    }

    /** Ces rôles doivent être rattachés à un compte utilisateur. */
    public function requiresUser(): bool
    {
        return \in_array($this, [self::TaikaiAdmin, self::DojoAdmin, self::MarkingReferee], true);
    }

    /** Ces rôles doivent être rattachés à un club hôte. */
    public function requiresParticipatingDojo(): bool
    {
        return \in_array($this, [self::DojoAdmin, self::MarkingReferee, self::Yatori], true);
    }
}

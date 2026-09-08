<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dojo;
use App\Entity\Kyudojin;
use App\Entity\Taikai;
use App\Entity\User;
use App\Repository\DojoRepository;
use App\Repository\KyudojinRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Autocomplétion, portée de `search_controller`.
 *
 * Rails rend un fragment HTML consommé par un contrôleur Stimulus
 * (`autocomplete_controller.js`), qui n'est pas porté (voir `MIGRATION.md`) :
 * les formulaires continuent d'utiliser des listes déroulantes. Ces points
 * d'entrée renvoient donc du JSON, prêts à être branchés le jour où
 * l'autocomplétion sera portée.
 */
#[IsGranted('ROLE_USER')]
final class SearchController extends AbstractController
{
    /**
     * Licenciés du référentiel fédéral, tous taikais confondus.
     *
     * Alimente le widget TomSelect de {@see \App\Form\Autocomplete\KyudojinAutocompleteType}
     * : la réponse est enveloppée dans `results`, format attendu par le
     * contrôleur Stimulus fourni par symfony/ux-autocomplete.
     */
    #[Route('/kyudojins/available', name: 'app_search_kyudojins', methods: ['GET'])]
    public function kyudojins(Request $request, KyudojinRepository $kyudojins): JsonResponse
    {
        $query = trim((string) $request->query->get('query', ''));

        return $this->json(['results' => array_map(
            static fn (Kyudojin $k): array => [
                'id' => $k->getId(),
                'label' => $k->getDisplayName(),
                'club' => $k->getFederationClub(),
                'firstname' => $k->getFirstname(),
                'lastname' => $k->getLastname(),
            ],
            $kyudojins->search($query),
        )]);
    }

    /**
     * Comptes utilisateur pouvant rejoindre le staff du taikai : exclut ceux déjà
     * membres du staff, sauf celui actuellement affecté au membre en cours d'édition.
     *
     * Alimente le widget TomSelect de {@see \App\Form\Autocomplete\StaffUserAutocompleteType}
     * : la réponse est enveloppée dans `results`, format attendu par le
     * contrôleur Stimulus fourni par symfony/ux-autocomplete.
     */
    #[Route('/taikais/{taikaiId}/staffs/available-users', name: 'app_search_staff_users', requirements: ['taikaiId' => '\d+'], methods: ['GET'])]
    public function staffUsers(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        Request $request,
        UserRepository $users,
    ): JsonResponse {
        $query = trim((string) $request->query->get('query', ''));
        $editedStaffId = $request->query->get('staffId');

        $excludedUserIds = [];
        foreach ($taikai->getStaffs() as $staff) {
            if (null !== $editedStaffId && (string) $staff->getId() === (string) $editedStaffId) {
                continue;
            }

            $userId = $staff->getUser()?->getId();
            if (null !== $userId) {
                $excludedUserIds[] = $userId;
            }
        }

        $matches = array_filter(
            $users->search($query),
            static fn (User $u): bool => !\in_array($u->getId(), $excludedUserIds, true),
        );

        return $this->json(['results' => array_map(
            static fn (User $u): array => [
                'id' => $u->getId(),
                'label' => $u->getDisplayName(),
                'email' => $u->getEmailAddress(),
                'firstname' => $u->getFirstname(),
                'lastname' => $u->getLastname(),
            ],
            array_values($matches),
        )]);
    }

    /**
     * Clubs pouvant devenir club hôte du taikai : exclut ceux déjà présents, sauf
     * celui actuellement rattaché au club hôte en cours d'édition.
     */
    #[Route('/taikais/{taikaiId}/participating-dojos/available-dojos', name: 'app_search_participating_dojo_dojos', requirements: ['taikaiId' => '\d+'], methods: ['GET'])]
    public function participatingDojoDojos(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        Request $request,
        DojoRepository $dojos,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));
        $editedParticipatingDojoId = $request->query->get('participatingDojoId');

        $excludedDojoIds = [];
        foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
            if (null !== $editedParticipatingDojoId && (string) $participatingDojo->getId() === (string) $editedParticipatingDojoId) {
                continue;
            }

            $dojoId = $participatingDojo->getDojo()?->getId();
            if (null !== $dojoId) {
                $excludedDojoIds[] = $dojoId;
            }
        }

        $matches = array_filter(
            $dojos->search($query),
            static fn (Dojo $d): bool => !\in_array($d->getId(), $excludedDojoIds, true),
        );

        return $this->json(array_map(
            static fn (Dojo $d): array => ['id' => $d->getId(), 'label' => $d->getShortname(), 'name' => $d->getName()],
            array_values($matches),
        ));
    }
}

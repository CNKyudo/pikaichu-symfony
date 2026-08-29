<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ParticipatingDojo;
use App\Entity\Taikai;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des tachis sur le shajo, porté de `tachis_controller`.
 *
 * Écran de lecture seule ; comme côté Rails, ouvert à tout utilisateur
 * authentifié (`tachis_controller` n'appelle pas non plus `authorize`, mais il
 * n'expose ici rien de plus que la feuille de marque ou le classement, déjà
 * ouverts au même niveau).
 */
#[Route(
    '/taikais/{taikaiId}/participating-dojos/{participatingDojoId}/tachis',
    requirements: ['taikaiId' => '\d+', 'participatingDojoId' => '\d+'],
)]
#[IsGranted('ROLE_USER')]
final class TachiController extends AbstractController
{
    use AssertsEntityOwnershipTrait;

    #[Route('', name: 'app_tachi_index', methods: ['GET'])]
    public function index(
        #[MapEntity(id: 'taikaiId')] Taikai $taikai,
        #[MapEntity(id: 'participatingDojoId')] ParticipatingDojo $participatingDojo,
    ): Response {
        $this->assertBelongsTo($participatingDojo->getTaikai(), $taikai, 'Participating dojo does not belong to this taikai');

        return $this->render('tachi/index.html.twig', [
            'taikai' => $taikai,
            'participatingDojo' => $participatingDojo,
            'tachis' => $participatingDojo->getTachis(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DojoRepository;
use App\Repository\TaikaiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableau de bord, reprise de `home#index` : nombre de taikai et de clubs
 * gérés, avec un raccourci vers chaque liste.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(TaikaiRepository $taikaiRepository, DojoRepository $dojoRepository): Response
    {
        if (null === $this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('home/index.html.twig', [
            'numTaikais' => $taikaiRepository->count([]),
            'numDojos' => $dojoRepository->count([]),
        ]);
    }
}

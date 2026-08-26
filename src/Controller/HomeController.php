<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page d'accueil.
 *
 * Comme la racine de l'application Rails, elle redirige vers la liste des taikai
 * pour un utilisateur connecté, et vers la connexion sinon.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (null === $this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        return $this->redirectToRoute('app_taikai_index');
    }
}

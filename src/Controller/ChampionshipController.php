<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TaikaiRepository;
use App\Service\ChampionshipExportService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Classement annuel du championnat, porté de `championship_controller`.
 */
#[Route('/championship')]
#[IsGranted('ROLE_USER')]
final class ChampionshipController extends AbstractController
{
    public function __construct(
        private readonly TaikaiRepository $taikaiRepository,
        private readonly ChampionshipExportService $exportService,
    ) {
    }

    #[Route('', name: 'app_championship_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('championship/index.html.twig', [
            'years' => $this->taikaiRepository->findChampionshipYears(),
        ]);
    }

    #[Route('/{year}/export', name: 'app_championship_export', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function export(int $year): StreamedResponse
    {
        $spreadsheet = $this->exportService->export($year);
        $writer = new Xlsx($spreadsheet);

        $response = new StreamedResponse(static function () use ($writer): void {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('Championnat %d au %s.xlsx', $year, new \DateTimeImmutable()->format('Y-m-d')),
        ));

        return $response;
    }
}

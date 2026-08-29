<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RankedGroup;
use App\DTO\ScoreValue;
use App\Entity\Participant;
use App\Entity\Result;
use App\Entity\Score;
use App\Entity\Taikai;
use App\Entity\Team;
use App\Enum\ResultStatus;
use App\Enum\TaikaiForm;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Export Excel d'un taikai, porté de `app/helpers/axlsx_export_helpers.rb` et
 * `taikais_controller#export`.
 *
 * Le tableau à matchs (`export_matches_results`) reste volontairement une
 * feuille vide côté Rails : la table de résultats correspondante est
 * commentée dans le code d'origine (« TODO: should we fix this and display
 * results? »), jamais terminée. Le portage reprend cette même intention —
 * feuille présente mais sans tableau — plutôt que d'achever ce que Rails a
 * laissé de côté.
 */
final readonly class TaikaiExportService
{
    private const int PAPER_SIZE_A4 = PageSetup::PAPERSIZE_A4;

    public function __construct(
        private TranslatorInterface $translator,
        private LeaderboardService $leaderboardService,
    ) {
    }

    public function export(Taikai $taikai): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->addSummarySheet($spreadsheet, $taikai);
        $this->addStaffSheet($spreadsheet, $taikai);
        $this->addParticipantsSheet($spreadsheet, $taikai);
        $this->addResultsSheets($spreadsheet, $taikai);
        $this->addJournalSheet($spreadsheet, $taikai);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function addSummarySheet(Spreadsheet $spreadsheet, Taikai $taikai): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->trans('taikai.export.summary'));
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(60);
        $this->setPortrait($sheet);

        $row = 1;
        $sheet->setCellValue("A{$row}", $this->trans('taikai.export.infos'));
        $this->mergeAndStyleHeader($sheet, "A{$row}:B{$row}");
        ++$row;

        $this->addLabelRow($sheet, $row++, $this->trans('taikai.shortname'), $taikai->getShortname() ?? '');
        $this->addLabelRow($sheet, $row++, $this->trans('taikai.name'), $taikai->getName() ?? '');
        $this->addDateRow($sheet, $row++, $this->trans('taikai.start_date'), $taikai->getStartDate());
        $this->addDateRow($sheet, $row++, $this->trans('taikai.end_date'), $taikai->getEndDate());
        $this->addWrappedRow($sheet, $row++, $this->trans('taikai.description'), $taikai->getDescription() ?? '');

        $type = $this->trans($taikai->getForm()?->label() ?? '').' - '.$this->trans($taikai->getScoring()->label());
        if ($taikai->isDistributed()) {
            $type .= ' ('.$this->trans('taikai.distributed').')';
        }

        $this->addLabelRow($sheet, $row++, $this->trans('taikai.export.type'), $type);
        $this->addLabelRow($sheet, $row++, $this->trans('taikai.total_num_arrows'), (string) $taikai->getTotalNumArrows());
        $this->addLabelRow($sheet, $row++, $this->trans('taikai.tachi_size'), (string) $taikai->getTachiSize());

        $row += 3;

        $sheet->setCellValue("A{$row}", $this->trans('taikai.host_clubs'));
        $this->mergeAndStyleHeader($sheet, "A{$row}:B{$row}");
        ++$row;

        foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
            $dojo = $participatingDojo->getDojo();
            $countryName = null !== $dojo?->getCountryCode() ? Countries::getName($dojo->getCountryCode(), 'fr') : '';
            $city = '' === ($dojo?->getCity() ?? '') ? '' : ', '.$dojo?->getCity();
            $this->addWrappedRow(
                $sheet,
                $row++,
                $participatingDojo->getDisplayName() ?? '',
                \sprintf('%s (%s)%s, %s', $dojo?->getShortname(), $dojo?->getName(), $city, $countryName),
            );
        }
    }

    private function addStaffSheet(Spreadsheet $spreadsheet, Taikai $taikai): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->trans('taikai.export.staff.title'));
        $sheet->getColumnDimension('A')->setWidth(23);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(17);
        $this->setPortrait($sheet);

        $row = 1;
        $this->addHeaderRow($sheet, $row++, [
            $this->trans('taikai.export.staff.lastname'),
            $this->trans('taikai.export.staff.firstname'),
            $this->trans('taikai.export.staff.role'),
            $this->trans('taikai.export.staff.participating_dojo'),
        ]);

        foreach ($taikai->getStaffs() as $staff) {
            $participatingDojo = $staff->getParticipatingDojo();
            $this->addDataRow($sheet, $row++, [
                $staff->getLastname() ?? '',
                $staff->getFirstname() ?? '',
                $staff->getRole()?->translatedLabel('fr') ?? '',
                null === $participatingDojo
                    ? ''
                    : \sprintf('%s (%s)', $participatingDojo->getDisplayName(), $participatingDojo->getDojo()?->getShortname()),
            ]);
        }
    }

    private function addParticipantsSheet(Spreadsheet $spreadsheet, Taikai $taikai): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->trans('taikai.export.participants.title'));
        $this->setPortrait($sheet);

        if (TaikaiForm::Individual === $taikai->getForm()) {
            $this->addIndividualParticipantsSheet($sheet, $taikai);
        } else {
            $this->addTeamParticipantsSheet($sheet, $taikai);
        }
    }

    private function addIndividualParticipantsSheet(Worksheet $sheet, Taikai $taikai): void
    {
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(4);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(12);

        $row = 1;
        $this->addHeaderRow($sheet, $row++, [
            $this->trans('taikai.export.participants.participating_dojo'),
            $this->trans('taikai.export.participants.index'),
            $this->trans('taikai.export.participants.display_name'),
            $this->trans('taikai.export.participants.club'),
        ]);

        foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
            if ($participatingDojo->getParticipants()->isEmpty()) {
                continue;
            }

            $dojoStartRow = $row;
            foreach ($participatingDojo->getParticipants() as $participant) {
                $this->addDataRow($sheet, $row++, [
                    \sprintf('%s (%s)', $participatingDojo->getDisplayName(), $participatingDojo->getDojo()?->getShortname()),
                    (string) ($participant->getIndex() ?? ''),
                    $participant->getDisplayName(),
                    $participant->getClub(),
                ]);
            }

            $sheet->mergeCells("A{$dojoStartRow}:A".($row - 1));
        }
    }

    private function addTeamParticipantsSheet(Worksheet $sheet, Taikai $taikai): void
    {
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(4);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(40);
        $sheet->getColumnDimension('E')->setWidth(12);

        $row = 1;
        $this->addHeaderRow($sheet, $row++, [
            $this->trans('taikai.export.participants.participating_dojo'),
            $this->trans('taikai.export.participants.index'),
            $this->trans('taikai.export.participants.team'),
            $this->trans('taikai.export.participants.display_name'),
            $this->trans('taikai.export.participants.club'),
        ]);

        foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
            if ($participatingDojo->getParticipants()->isEmpty()) {
                continue;
            }

            $dojoStartRow = $row;
            foreach ($participatingDojo->getTeams() as $team) {
                $teamStartRow = $row;
                foreach ($team->getParticipants() as $participant) {
                    $this->addDataRow($sheet, $row++, [
                        \sprintf('%s (%s)', $participatingDojo->getDisplayName(), $participatingDojo->getDojo()?->getShortname()),
                        (string) ($team->getIndex() ?? ''),
                        $team->getShortname() ?? '',
                        $participant->getDisplayName(),
                        $participant->getClub(),
                    ]);
                }

                if ($row > $teamStartRow) {
                    $sheet->mergeCells("B{$teamStartRow}:B".($row - 1));
                    $sheet->mergeCells("C{$teamStartRow}:C".($row - 1));
                }
            }

            if ($row > $dojoStartRow) {
                $sheet->mergeCells("A{$dojoStartRow}:A".($row - 1));
            }
        }
    }

    private function addResultsSheets(Spreadsheet $spreadsheet, Taikai $taikai): void
    {
        match ($taikai->getForm()) {
            TaikaiForm::Individual => $this->addIndividualResultsSheet($spreadsheet, $taikai),
            TaikaiForm::Team => $this->addTeamResultsSheet($spreadsheet, $taikai),
            TaikaiForm::TwoInOne => (function () use ($spreadsheet, $taikai): void {
                $this->addIndividualResultsSheet($spreadsheet, $taikai);
                $this->addTeamResultsSheet($spreadsheet, $taikai);
            })(),
            TaikaiForm::Matches => $this->addMatchesResultsSheet($spreadsheet),
            null => null,
        };
    }

    private function addIndividualResultsSheet(Spreadsheet $spreadsheet, Taikai $taikai): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->trans('taikai.export.results.title.individual'));
        $this->setLandscape($sheet);

        [$overall, $byDojo] = $this->leaderboardService->computeIndividualLeaderboard($taikai);

        $row = 1;
        $row = $this->writeIndividualResultsTable($sheet, $row, $taikai, $overall);

        if ($taikai->isDistributed()) {
            foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
                if ($participatingDojo->getParticipants()->isEmpty()) {
                    continue;
                }

                $row += 2;
                $groups = $byDojo[(int) $participatingDojo->getId()] ?? [];
                $row = $this->writeIndividualResultsTable($sheet, $row, $taikai, $groups);
            }
        }
    }

    /** @param list<RankedGroup<Participant>> $groups */
    private function writeIndividualResultsTable(Worksheet $sheet, int $row, Taikai $taikai, array $groups): int
    {
        $numRounds = $taikai->getNumRounds();
        $lastColumnIndex = 4 + $taikai->getTotalNumArrows() + 1;
        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);

        $headerRow = [
            $this->trans('taikai.export.results.rank'),
            $this->trans('taikai.export.results.index'),
            $taikai->isDistributed() ? $this->trans('taikai.export.results.participating_dojo') : $this->trans('taikai.export.results.club'),
            $this->trans('taikai.export.results.display_name'),
        ];
        for ($i = 1; $i <= $numRounds; ++$i) {
            $headerRow[] = $this->trans('taikai.export.results.round', ['%count%' => $i]);
            $headerRow[] = '';
            $headerRow[] = '';
            $headerRow[] = '';
        }

        $headerRow[] = $this->trans('taikai.export.results.score');

        $this->addHeaderRow($sheet, $row, $headerRow);
        for ($i = 0; $i < $numRounds; ++$i) {
            $start = Coordinate::stringFromColumnIndex(5 + 4 * $i);
            $end = Coordinate::stringFromColumnIndex(5 + 4 * $i + 3);
            $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
        }

        ++$row;

        foreach ($groups as $group) {
            $groupStartRow = $row;
            foreach ($group->members as $participant) {
                $score = $participant->getScore();
                $marks = null === $score ? array_fill(0, $taikai->getTotalNumArrows(), '') : $this->arrowMarks($score);

                $rowValues = [
                    (string) $group->rank,
                    (string) ($participant->getIndex() ?? ''),
                    $taikai->isDistributed()
                        ? ($participant->getParticipatingDojo()?->getDisplayName() ?? '')
                        : $participant->getClub(),
                    $participant->getDisplayName(),
                    ...$marks,
                    $this->displayScore($score?->toScoreValue(), $taikai->getScoring()->usesArrowValues()),
                ];
                $this->addResultRow($sheet, $row++, $rowValues);
            }

            $sheet->mergeCells("A{$groupStartRow}:A".($row - 1));
            $sheet->mergeCells("{$lastColumn}{$groupStartRow}:{$lastColumn}".($row - 1));
        }

        return $row;
    }

    private function addTeamResultsSheet(Spreadsheet $spreadsheet, Taikai $taikai): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->trans('taikai.export.results.title.team'));
        $this->setLandscape($sheet);

        [$overall, $byDojo] = $this->leaderboardService->computeTeamLeaderboard($taikai);

        $row = 1;
        $row = $this->writeTeamResultsTable($sheet, $row, $taikai, $overall);

        if ($taikai->isDistributed()) {
            foreach ($taikai->getParticipatingDojos() as $participatingDojo) {
                if ($participatingDojo->getParticipants()->isEmpty()) {
                    continue;
                }

                $row += 2;
                $groups = $byDojo[(int) $participatingDojo->getId()] ?? [];
                $row = $this->writeTeamResultsTable($sheet, $row, $taikai, $groups);
            }
        }
    }

    /** @param list<RankedGroup<Team>> $groups */
    private function writeTeamResultsTable(Worksheet $sheet, int $row, Taikai $taikai, array $groups): int
    {
        $numRounds = $taikai->getNumRounds();
        $lastColumnIndex = 5 + $taikai->getTotalNumArrows() + 2;
        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);

        $headerRow = [
            $this->trans('taikai.export.results.rank'),
            $this->trans('taikai.export.results.index'),
            $this->trans('taikai.export.results.team'),
            $taikai->isDistributed() ? $this->trans('taikai.export.results.participating_dojo') : $this->trans('taikai.export.results.club'),
            $this->trans('taikai.export.results.display_name'),
        ];
        for ($i = 1; $i <= $numRounds; ++$i) {
            $headerRow[] = $this->trans('taikai.export.results.round', ['%count%' => $i]);
            $headerRow[] = '';
            $headerRow[] = '';
            $headerRow[] = '';
        }

        $headerRow[] = $this->trans('taikai.export.results.score');
        $headerRow[] = $this->trans('taikai.export.results.team_score');

        $this->addHeaderRow($sheet, $row, $headerRow);
        for ($i = 0; $i < $numRounds; ++$i) {
            $start = Coordinate::stringFromColumnIndex(6 + 4 * $i);
            $end = Coordinate::stringFromColumnIndex(6 + 4 * $i + 3);
            $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
        }

        ++$row;

        $enteki = $taikai->getScoring()->usesArrowValues();
        foreach ($groups as $group) {
            $groupStartRow = $row;
            foreach ($group->members as $team) {
                if ($team->getParticipants()->isEmpty()) {
                    continue;
                }

                $teamStartRow = $row;
                $teamScore = $team->getScore();
                foreach ($team->getParticipants() as $participant) {
                    $score = $participant->getScore();
                    $marks = null === $score ? array_fill(0, $taikai->getTotalNumArrows(), '') : $this->arrowMarks($score);

                    $rowValues = [
                        (string) $group->rank,
                        (string) ($team->getIndex() ?? ''),
                        $team->getShortname() ?? '',
                        $taikai->isDistributed()
                            ? ($team->getParticipatingDojo()?->getDisplayName() ?? '')
                            : $participant->getClub(),
                        $participant->getDisplayName(),
                        ...$marks,
                        $this->displayScore($score?->toScoreValue(), $enteki),
                        $this->displayScore($teamScore?->toScoreValue(), $enteki),
                    ];
                    $this->addResultRow($sheet, $row++, $rowValues);
                }

                $sheet->mergeCells("B{$teamStartRow}:B".($row - 1));
                $sheet->mergeCells("C{$teamStartRow}:C".($row - 1));
                $sheet->mergeCells("D{$teamStartRow}:D".($row - 1));
            }

            if ($row > $groupStartRow) {
                $sheet->mergeCells("A{$groupStartRow}:A".($row - 1));
                $sheet->mergeCells("{$lastColumn}{$groupStartRow}:{$lastColumn}".($row - 1));
            }
        }

        return $row;
    }

    /**
     * Feuille volontairement vide : voir la note de classe sur
     * `export_matches_results`, jamais terminée côté Rails.
     */
    private function addMatchesResultsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->trans('taikai.export.results.title.matches'));
        $this->setLandscape($sheet);
    }

    private function addJournalSheet(Spreadsheet $spreadsheet, Taikai $taikai): void
    {
        if ($taikai->getEvents()->isEmpty()) {
            return;
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->trans('taikai.export.journal.title'));
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(20);
        $this->setLandscape($sheet);

        $row = 1;
        $this->addHeaderRow($sheet, $row++, [
            $this->trans('taikai.export.journal.time'),
            $this->trans('taikai.export.journal.user'),
            $this->trans('taikai.export.journal.message'),
            $this->trans('taikai.export.journal.category'),
        ]);

        foreach ($taikai->getEvents() as $event) {
            $createdAt = $event->getCreatedAt();
            $this->addDataRow($sheet, $row++, [
                null === $createdAt ? '' : $createdAt->format('d/m/Y H:i'),
                $event->getUser()?->getDisplayName() ?? '',
                $event->getMessage() ?? '',
                $this->trans('taikai.event.category.'.$event->getCategory()),
            ]);
        }
    }

    /** @return list<string> */
    private function arrowMarks(Score $score): array
    {
        return array_values(array_map($this->resultMark(...), $score->getResults()->toArray()));
    }

    private function resultMark(Result $result): string
    {
        if (!$result->isFinal()) {
            return '';
        }

        if (null !== $result->getValue()) {
            return (string) $result->getValue();
        }

        return match ($result->getStatus()) {
            ResultStatus::Hit => 'O',
            ResultStatus::Miss => 'X',
            default => '',
        };
    }

    private function displayScore(?ScoreValue $score, bool $enteki): string
    {
        if (null === $score) {
            return $enteki ? '0 / 0' : '0';
        }

        return $enteki ? \sprintf('%d / %d', $score->value ?? 0, $score->hits) : (string) $score->hits;
    }

    /** @param array<string, string|int> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    private function setPortrait(Worksheet $sheet): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(self::PAPER_SIZE_A4);
    }

    private function setLandscape(Worksheet $sheet): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(self::PAPER_SIZE_A4);
    }

    private function mergeAndStyleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->mergeCells($range);
        $sheet->getStyle($range)->applyFromArray($this->headerStyle());
    }

    /** @param list<string> $values */
    private function addHeaderRow(Worksheet $sheet, int $row, array $values): void
    {
        $sheet->fromArray($values, null, "A{$row}");
        $lastColumn = Coordinate::stringFromColumnIndex(\count($values));
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($this->headerStyle());
        $sheet->getRowDimension($row)->setRowHeight(30);
    }

    /** @param list<string> $values */
    private function addDataRow(Worksheet $sheet, int $row, array $values): void
    {
        $sheet->fromArray($values, null, "A{$row}");
        $lastColumn = Coordinate::stringFromColumnIndex(\count($values));
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($this->dataStyle());
    }

    /** @param list<string> $values */
    private function addResultRow(Worksheet $sheet, int $row, array $values): void
    {
        $sheet->fromArray($values, null, "A{$row}");
        $lastColumn = Coordinate::stringFromColumnIndex(\count($values));
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($this->centeredStyle());
    }

    private function addLabelRow(Worksheet $sheet, int $row, string $label, string $value): void
    {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $value);
        $sheet->getStyle("A{$row}")->applyFromArray($this->labelStyle());
        $sheet->getStyle("B{$row}")->applyFromArray($this->dataStyle());
    }

    private function addWrappedRow(Worksheet $sheet, int $row, string $label, string $value): void
    {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $value);
        $sheet->getStyle("A{$row}")->applyFromArray($this->labelStyle());
        $sheet->getStyle("B{$row}")->applyFromArray($this->wrappedStyle());
    }

    private function addDateRow(Worksheet $sheet, int $row, string $label, ?\DateTimeImmutable $date): void
    {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->getStyle("A{$row}")->applyFromArray($this->labelStyle());

        if (null !== $date) {
            $sheet->setCellValue("B{$row}", ExcelDate::PHPToExcel($date));
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        }

        $sheet->getStyle("B{$row}")->applyFromArray($this->dataStyle());
    }

    /** @return array<string, mixed> */
    private function headerStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
    }

    /** @return array<string, mixed> */
    private function labelStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
    }

    /** @return array<string, mixed> */
    private function dataStyle(): array
    {
        return [
            'font' => ['size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
    }

    /** @return array<string, mixed> */
    private function wrappedStyle(): array
    {
        return [
            'font' => ['size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
    }

    /** @return array<string, mixed> */
    private function centeredStyle(): array
    {
        return [
            'font' => ['size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
    }
}

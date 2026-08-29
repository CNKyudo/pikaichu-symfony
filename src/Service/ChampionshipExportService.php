<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScoreValue;
use App\Entity\Participant;
use App\Entity\Taikai;
use App\Enum\TaikaiForm;
use App\Enum\TaikaiScoring;
use App\Enum\TaikaiState;
use App\Repository\TaikaiRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Classement annuel du championnat, porté de `championship_controller` et de sa
 * vue `championship/export.xlsx.axlsx`.
 *
 * `rank()` reproduit `ChampionshipController#rank` : les participants sont
 * regroupés par **nom affiché**, pas par identifiant — chaque taikai a ses
 * propres lignes `Participant`, il n'existe pas d'entité « personne » stable
 * entre taikais. Seuls ceux qui ont au moins 3 participations dans l'année
 * sont classés, sur la base de leurs 3 meilleurs résultats.
 *
 * `participant.score.first(12).score_value` côté Rails ne peut pas fonctionner
 * tel quel (`first` n'existe pas sur un `Score`) : le portage reprend
 * l'intention évidente, `participant.score.score_value`.
 */
final readonly class ChampionshipExportService
{
    private const array RANKED_CATEGORIES = ['A', 'B', 'C'];

    public function __construct(
        private TaikaiRepository $taikaiRepository,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function export(int $year): Spreadsheet
    {
        $taikais = $this->rankedTaikaisForYear($year);

        $kintekiTaikais = array_values(array_filter(
            $taikais,
            static fn (Taikai $taikai): bool => TaikaiScoring::Kinteki === $taikai->getScoring(),
        ));
        $entekiTaikais = array_values(array_filter(
            $taikais,
            static fn (Taikai $taikai): bool => TaikaiScoring::Enteki === $taikai->getScoring(),
        ));

        $kintekiParticipants = $this->collectParticipants($kintekiTaikais);
        $entekiParticipants = $this->collectParticipants($entekiTaikais);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->addRankingSheet($spreadsheet, 'Kinteki Classement Indiv', $this->rank($kintekiParticipants), false);
        $this->addRankingSheet($spreadsheet, 'Enteki Classement Indiv', $this->rank($entekiParticipants), true);
        $this->addResultsSheet($spreadsheet, 'Kinteki Résultats', $kintekiParticipants, false);
        $this->addResultsSheet($spreadsheet, 'Enteki Résultats', $entekiParticipants, true);
        $this->addDebugTaikaisSheet($spreadsheet, [...$kintekiTaikais, ...$entekiTaikais]);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Taikai terminés, catégorisés A/B/C, individuels ou 2-en-1, d'une année.
     *
     * @return list<Taikai>
     */
    private function rankedTaikaisForYear(int $year): array
    {
        return array_values(array_filter(
            $this->taikaiRepository->findFinishedForYear($year),
            static fn (Taikai $taikai): bool => $taikai->isState(TaikaiState::Done)
                && \in_array($taikai->getCategory(), self::RANKED_CATEGORIES, true)
                && $taikai->isForm(TaikaiForm::Individual, TaikaiForm::TwoInOne),
        ));
    }

    /**
     * @param list<Taikai> $taikais
     *
     * @return list<array{Participant, ScoreValue}>
     */
    private function collectParticipants(array $taikais): array
    {
        $pairs = [];
        foreach ($taikais as $taikai) {
            foreach ($taikai->getParticipants() as $participant) {
                $pairs[] = [$participant, $participant->getScore()?->toScoreValue() ?? new ScoreValue(hits: 0)];
            }
        }

        return $pairs;
    }

    /**
     * @param list<array{Participant, ScoreValue}> $pairs
     *
     * @return list<array{rank: int, participant: Participant, club: string, total: ScoreValue}>
     */
    private function rank(array $pairs): array
    {
        $groupedByName = [];
        foreach ($pairs as $pair) {
            $groupedByName[$pair[0]->getDisplayName()][] = $pair;
        }

        $ranked = [];
        foreach ($groupedByName as $group) {
            // Il faut au moins 3 participations dans l'année pour être classé.
            if (\count($group) < 3) {
                continue;
            }

            usort($group, static fn (array $a, array $b): int => $a[1]->compareTo($b[1]));
            $best3 = \array_slice($group, -3);

            $total = new ScoreValue(hits: 0);
            foreach ($best3 as [, $score]) {
                $total = $total->plus($score);
            }

            $ranked[] = [
                'participant' => $best3[0][0],
                'club' => $best3[0][0]->getClub(),
                'total' => $total,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['total']->compareTo($a['total']));

        foreach ($ranked as $index => &$entry) {
            $entry['rank'] = $index + 1;
        }

        return $ranked;
    }

    /** @param list<array{rank: int, participant: Participant, club: string, total: ScoreValue}> $ranking */
    private function addRankingSheet(Spreadsheet $spreadsheet, string $title, array $ranking, bool $enteki): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        $row = 1;
        $this->addHeaderRow($sheet, $row++, [
            $this->trans('championship.export.rank'),
            $this->trans('championship.export.display_name'),
            $this->trans('championship.export.club'),
            $this->trans('championship.export.total'),
        ]);

        foreach ($ranking as $entry) {
            $this->addDataRow($sheet, $row++, [
                (string) $entry['rank'],
                $this->displayName($entry['participant']),
                $entry['club'],
                (string) ($enteki ? $entry['total']->value : $entry['total']->hits),
            ]);
        }
    }

    /** @param list<array{Participant, ScoreValue}> $pairs */
    private function addResultsSheet(Spreadsheet $spreadsheet, string $title, array $pairs, bool $enteki): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        $row = 1;
        $this->addHeaderRow($sheet, $row++, [
            $this->trans('championship.export.taikai_name'),
            $this->trans('championship.export.category'),
            $this->trans('championship.export.display_name'),
            $this->trans('championship.export.club'),
            $this->trans('championship.export.total'),
            $this->trans('championship.export.rank'),
        ]);

        foreach ($pairs as [$participant, $score]) {
            $taikai = $participant->getTaikai();
            $this->addDataRow($sheet, $row, [
                $taikai?->getName() ?? '',
                $taikai?->getCategory() ?? '',
                $this->displayName($participant),
                $participant->getClub(),
                (string) ($enteki ? $score->value : $score->hits),
                (string) ($participant->getRank() ?? ''),
            ]);
            if (null !== $taikai?->getId()) {
                $sheet->getCell("A{$row}")->getHyperlink()->setUrl(
                    $this->urlGenerator->generate('app_taikai_show', ['id' => $taikai->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                );
            }

            ++$row;
        }

        if ($row > 2) {
            $sheet->setAutoFilter('A1:F'.($row - 1));
        }
    }

    /** @param list<Taikai> $taikais */
    private function addDebugTaikaisSheet(Spreadsheet $spreadsheet, array $taikais): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('DEBUG - Taikais');

        $row = 1;
        $this->addHeaderRow($sheet, $row++, [
            $this->trans('championship.export.taikai_name'),
            'Scoring',
            'Forme',
            $this->trans('taikai.category'),
            'Participants',
            'Nb. clubs',
        ]);

        foreach ($taikais as $taikai) {
            $clubs = array_unique(array_map(
                static fn (Participant $participant): string => $participant->getClub(),
                $taikai->getParticipants(),
            ));

            $this->addDataRow($sheet, $row, [
                $taikai->getName() ?? '',
                $taikai->getScoring()->value,
                $taikai->getForm()->value,
                $taikai->getCategory() ?? '',
                (string) \count($taikai->getParticipants()),
                (string) \count($clubs),
            ]);
            if (null !== $taikai->getId()) {
                $sheet->getCell("A{$row}")->getHyperlink()->setUrl(
                    $this->urlGenerator->generate('app_taikai_show', ['id' => $taikai->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                );
            }

            ++$row;
        }

        if ($row > 2) {
            $sheet->setAutoFilter('A1:F'.($row - 1));
        }
    }

    /**
     * Formatage spécifique à cet export (NOM en capitales, Prénom capitalisé),
     * distinct de `Participant::getDisplayName()` (« Prénom Nom ») utilisé
     * ailleurs dans l'application — Rails définit ici aussi un `display_name`
     * local qui prévaut sur celui du modèle, pour la même raison.
     */
    private function displayName(Participant $participant): string
    {
        $lastname = mb_strtoupper($participant->getLastname() ?? '', 'UTF-8');
        $firstname = $this->titleCase($participant->getFirstname() ?? '');

        return trim($lastname.' '.$firstname);
    }

    private function titleCase(string $name): string
    {
        $result = preg_replace_callback(
            '/[\p{L}\'’]+/u',
            static fn (array $matches): string => mb_convert_case($matches[0], \MB_CASE_TITLE, 'UTF-8'),
            $name,
        );

        return $result ?? $name;
    }

    /** @param list<string> $values */
    private function addHeaderRow(Worksheet $sheet, int $row, array $values): void
    {
        $sheet->fromArray($values, null, "A{$row}");
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(\count($values));
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    /** @param list<string> $values */
    private function addDataRow(Worksheet $sheet, int $row, array $values): void
    {
        $sheet->fromArray($values, null, "A{$row}");
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(\count($values));
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key);
    }
}

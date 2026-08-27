<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Kyudojin;
use App\Entity\Participant;
use App\Entity\ParticipatingDojo;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import des participants depuis un export « Kyudo - Interface de gestion ».
 *
 * Le classeur porte une bannière avant son en-tête ; on cherche donc la première
 * ligne contenant les colonnes attendues plutôt que de supposer sa position.
 */
final readonly class ParticipantImporter
{
    /** Bannière de l'export, à ignorer avant l'en-tête. */
    private const string BANNER = 'Kyudo - Interface de gestion';

    private const string COLUMN_FIRSTNAME = 'Prénom';

    private const string COLUMN_LASTNAME = 'Nom';

    private const string COLUMN_CLUB = 'Club';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
    ) {
    }

    public function import(ParticipatingDojo $participatingDojo, string $path): ParticipantImportReport
    {
        $rows = $this->readRows($path);

        $imported = 0;
        $notFound = [];
        $failed = [];

        foreach ($rows as $row) {
            $firstname = trim($row[self::COLUMN_FIRSTNAME] ?? '');
            $lastname = trim($row[self::COLUMN_LASTNAME] ?? '');
            $club = trim($row[self::COLUMN_CLUB] ?? '');

            if ('' === $firstname && '' === $lastname) {
                continue;
            }

            $displayName = trim($firstname.' '.$lastname);

            // Le fichier fait foi pour l'identité affichée ; le licencié ne sert
            // qu'à rattacher le participant au référentiel fédéral.
            $participant = new Participant();
            $participant->setFirstname($firstname)
                ->setLastname($lastname)
                ->setClub($club);

            $kyudojin = $this->findKyudojin($firstname, $lastname, $club);
            if ($kyudojin instanceof Kyudojin) {
                $participant->setKyudojin($kyudojin);
            } else {
                $notFound[] = $displayName;
            }

            $participatingDojo->addParticipant($participant);

            if (\count($this->validator->validate($participant)) > 0) {
                $participatingDojo->removeParticipant($participant);
                $failed[] = $displayName;

                continue;
            }

            $this->entityManager->persist($participant);
            ++$imported;
        }

        $this->entityManager->flush();

        return new ParticipantImportReport($imported, $notFound, $failed);
    }

    /**
     * Lignes de la première feuille, indexées par en-tête.
     *
     * @return list<array<string, string>>
     */
    private function readRows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $sheet = $reader->load($path)->getSheet(0);

        /** @var list<list<string|null>> $raw */
        $raw = $sheet->toArray(null, true, false, false);

        $headers = null;
        $rows = [];

        foreach ($raw as $cells) {
            $values = array_map(static fn (?string $cell): string => trim((string) $cell), $cells);

            if ($this->isBanner($values)) {
                continue;
            }

            if (null === $headers) {
                if (\in_array(self::COLUMN_FIRSTNAME, $values, true)) {
                    $headers = $values;
                }

                continue;
            }

            $row = [];
            foreach ($headers as $position => $header) {
                if ('' !== $header) {
                    $row[$header] = $values[$position] ?? '';
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @param list<string> $values */
    private function isBanner(array $values): bool
    {
        foreach ($values as $value) {
            if ('' !== $value) {
                return str_contains($value, self::BANNER);
            }
        }

        return true;
    }

    /**
     * Rapprochement avec la base des licenciés.
     *
     * Rails compare en majuscules alors que les licenciés sont désormais stockés
     * en casse de titre : la comparaison littérale ne pourrait plus aboutir. On
     * porte donc l'intention — ignorer casse, accents et traits d'union — en
     * repliant les deux côtés, sur le petit lot de licenciés du club concerné.
     */
    private function findKyudojin(string $firstname, string $lastname, string $club): ?Kyudojin
    {
        if ('' === $club) {
            return null;
        }

        $candidates = $this->entityManager->getRepository(Kyudojin::class)->findBy([
            'federationClub' => $club,
            'federationCountryCode' => 'FR',
        ]);

        foreach ($candidates as $candidate) {
            if ($this->fold((string) $candidate->getFirstname()) === $this->fold($firstname)
                && $this->fold((string) $candidate->getLastname()) === $this->fold($lastname)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /** Replie un nom : sans accent, en majuscules, traits d'union et espaces unifiés. */
    private function fold(string $name): string
    {
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Upper()', $name);
        $name = false === $transliterated ? mb_strtoupper($name) : $transliterated;

        return trim((string) preg_replace('/[\s-]+/u', ' ', $name));
    }
}

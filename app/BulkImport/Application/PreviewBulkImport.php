<?php

declare(strict_types=1);

namespace App\BulkImport\Application;

use App\BulkImport\Application\Contract\BulkImportWorkbookReader;
use App\BulkImport\Application\Dto\BulkImportClassification;
use App\BulkImport\Application\Dto\PreviewBulkImportResult;
use App\BulkImport\Application\Dto\SafePreviewItem;
use App\BulkImport\Application\Planning\BulkImportMatcher;
use App\BulkImport\Application\Planning\FamilyImportPlan;
use App\BulkImport\Application\Planning\RepresentativeImportPlan;
use App\BulkImport\Application\Planning\StudentImportPlan;
use DateTimeImmutable;

final readonly class PreviewBulkImport
{
    public function __construct(
        private BulkImportWorkbookReader $reader,
        private BulkImportMatcher $matcher,
    ) {
    }

    public function handle(string $localPath, DateTimeImmutable $today): PreviewBulkImportResult
    {
        $validation = $this->reader->read($localPath, $today);
        $workbook = $validation->workbook();
        if (!$validation->isValid() || $workbook === null) {
            return new PreviewBulkImportResult(0, 0, 0, 0, [], $validation->issues());
        }

        $plan = $this->matcher->match($workbook);
        $items = [];
        foreach ($plan->families as $family) {
            $items[] = new SafePreviewItem(
                $family->row->familyCode->value(),
                $family->row->displayName,
                null,
                null,
                null,
                $family->classification,
                $this->message($family->classification, 'familia'),
            );
            foreach ($family->representatives as $representative) {
                $items[] = $this->representativeItem($family, $representative);
            }
            foreach ($family->students as $student) {
                $items[] = $this->studentItem($family, $student);
            }
        }

        $counts = [
            BulkImportClassification::New->value => 0,
            BulkImportClassification::AlreadyExists->value => 0,
            BulkImportClassification::Conflict->value => 0,
        ];
        foreach ($items as $item) {
            $counts[$item->classification->value]++;
        }

        return new PreviewBulkImportResult(
            count($plan->families),
            $counts[BulkImportClassification::New->value],
            $counts[BulkImportClassification::AlreadyExists->value],
            $counts[BulkImportClassification::Conflict->value],
            $items,
            [...$validation->issues(), ...$plan->issues],
        );
    }

    private function representativeItem(
        FamilyImportPlan $family,
        RepresentativeImportPlan $plan,
    ): SafePreviewItem {
        return new SafePreviewItem(
            $family->row->familyCode->value(),
            $family->row->displayName,
            $this->displayName(
                $plan->row->firstName,
                $plan->row->middleName,
                $plan->row->firstSurname,
                $plan->row->secondSurname,
            ),
            $this->maskDocument($plan->row->documentNumber),
            null,
            $plan->classification,
            $this->message($plan->classification, 'representante'),
        );
    }

    private function studentItem(
        FamilyImportPlan $family,
        StudentImportPlan $plan,
    ): SafePreviewItem {
        return new SafePreviewItem(
            $family->row->familyCode->value(),
            $family->row->displayName,
            $this->displayName(
                $plan->row->firstName,
                $plan->row->middleName,
                $plan->row->firstSurname,
                $plan->row->secondSurname,
            ),
            $plan->row->documentNumber === null
                ? null
                : $this->maskDocument($plan->row->documentNumber),
            $plan->row->institutionalCode->value(),
            $plan->classification,
            $this->message($plan->classification, 'estudiante'),
        );
    }

    private function displayName(
        string $firstName,
        ?string $middleName,
        string $firstSurname,
        ?string $secondSurname,
    ): string {
        return implode(' ', array_values(array_filter(
            [$firstName, $middleName, $firstSurname, $secondSurname],
            static fn (?string $part): bool => $part !== null && $part !== '',
        )));
    }

    private function maskDocument(string $value): string
    {
        $value = trim($value);
        $length = mb_strlen($value, 'UTF-8');
        if ($length <= 4) {
            return '****';
        }

        return str_repeat('*', $length - 4) . mb_substr($value, -4, null, 'UTF-8');
    }

    private function message(BulkImportClassification $classification, string $subject): string
    {
        return match ($classification) {
            BulkImportClassification::New => sprintf('Se creará o vinculará el %s.', $subject),
            BulkImportClassification::AlreadyExists => sprintf(
                'El %s ya existe de forma equivalente; se conservarán sus datos vivos.',
                $subject,
            ),
            BulkImportClassification::Conflict => sprintf(
                'El %s tiene un conflicto que impide aplicar esta familia.',
                $subject,
            ),
        };
    }
}

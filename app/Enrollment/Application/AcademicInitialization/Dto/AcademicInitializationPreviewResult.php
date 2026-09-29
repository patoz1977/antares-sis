<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Dto;

final readonly class AcademicInitializationPreviewResult
{
    /**
     * @param list<AcademicInitializationManifestRow> $rows
     * @param list<AcademicInitializationPreviewItem> $items
     * @param list<AcademicInitializationIssue> $issues
     */
    public function __construct(
        public string $academicPeriodCode,
        public array $rows,
        public array $items,
        public array $issues,
    ) {
    }

    public function isApplicable(): bool
    {
        return $this->rows !== []
            && $this->issues === []
            && $this->count(AcademicInitializationClassification::Conflict) === 0;
    }

    public function stateDigest(): string
    {
        $states = array_map(
            static fn (AcademicInitializationPreviewItem $item): array => $item->fingerprintState(),
            $this->items,
        );
        usort(
            $states,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['institutional_code'],
                (string) $right['institutional_code'],
            ),
        );
        $encoded = json_encode($states, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded);
    }

    /** @return array{academic_period_code: string, counts: array<string, int>, items: list<array<string, int|string>>, issues: list<array<string, int|string|null>>} */
    public function safeOutput(): array
    {
        return [
            'academic_period_code' => $this->academicPeriodCode,
            'counts' => [
                'rows' => count($this->rows),
                'create_draft' => $this->count(AcademicInitializationClassification::CreateDraft),
                'set_placement' => $this->count(AcademicInitializationClassification::SetPlacement),
                'already_correct' => $this->count(AcademicInitializationClassification::AlreadyCorrect),
                'conflicts' => $this->count(AcademicInitializationClassification::Conflict),
                'issues' => count($this->issues),
            ],
            'items' => array_map(
                static fn (AcademicInitializationPreviewItem $item): array => $item->safeOutput(),
                $this->items,
            ),
            'issues' => array_map(
                static fn (AcademicInitializationIssue $issue): array => $issue->safeOutput(),
                $this->issues,
            ),
        ];
    }

    private function count(AcademicInitializationClassification $classification): int
    {
        return count(array_filter(
            $this->items,
            static fn (AcademicInitializationPreviewItem $item): bool =>
                $item->classification === $classification,
        ));
    }
}

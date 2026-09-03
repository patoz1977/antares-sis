<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class PreviewBulkImportResult
{
    /**
     * @param list<SafePreviewItem> $items
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        public int $families,
        public int $new,
        public int $alreadyExists,
        public int $conflicts,
        public array $items,
        public array $issues,
    ) {
    }

    /** @return array<string, mixed> */
    public function safeOutput(): array
    {
        return [
            'counts' => [
                'families' => $this->families,
                'new' => $this->new,
                'already_exists' => $this->alreadyExists,
                'conflicts' => $this->conflicts,
                'issues' => count($this->issues),
            ],
            'items' => array_map(
                static fn (SafePreviewItem $item): array => $item->safeOutput(),
                $this->items,
            ),
            'issues' => array_map(
                static fn (ValidationIssue $issue): array => $issue->safeOutput(),
                $this->issues,
            ),
        ];
    }
}

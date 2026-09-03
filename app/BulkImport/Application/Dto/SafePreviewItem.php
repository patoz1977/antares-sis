<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class SafePreviewItem
{
    public function __construct(
        public string $familyCode,
        public string $displayName,
        public ?string $personDisplayName,
        public ?string $maskedDocumentNumber,
        public ?string $institutionalCode,
        public BulkImportClassification $classification,
        public string $message,
    ) {
    }

    /** @return array<string, ?string> */
    public function safeOutput(): array
    {
        return [
            'family_code' => $this->familyCode,
            'display_name' => $this->displayName,
            'person_display_name' => $this->personDisplayName,
            'masked_document_number' => $this->maskedDocumentNumber,
            'institutional_code' => $this->institutionalCode,
            'classification' => $this->classification->value,
            'message' => $this->message,
        ];
    }
}

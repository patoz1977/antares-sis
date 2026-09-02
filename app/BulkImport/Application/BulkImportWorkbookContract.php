<?php

declare(strict_types=1);

namespace App\BulkImport\Application;

final class BulkImportWorkbookContract
{
    public const MAXIMUM_ISSUES = 500;
    public const MAXIMUM_CELL_CHARACTERS = 1000;
    public const MAXIMUM_CELL_BYTES = 4000;
    public const MAXIMUM_TOTAL_ROWS = 5000;

    /** @var array<string, positive-int> */
    public const MAXIMUM_DATA_ROWS = [
        'Familias' => 1000,
        'Representantes' => 2000,
        'Estudiantes' => 3000,
    ];

    /** @var array<string, list<string>> */
    public const HEADERS = [
        'Familias' => [
            'family_code',
            'display_name',
        ],
        'Representantes' => [
            'family_code',
            'first_name',
            'middle_name',
            'first_surname',
            'second_surname',
            'birth_date',
            'sex_code',
            'document_type_code',
            'document_number',
            'email',
            'relationship_type_code',
            'is_primary',
            'started_at',
            'initial_password',
        ],
        'Estudiantes' => [
            'family_code',
            'first_name',
            'middle_name',
            'first_surname',
            'second_surname',
            'birth_date',
            'sex_code',
            'document_type_code',
            'document_number',
            'institutional_code',
            'admission_date',
            'started_at',
        ],
    ];

    private function __construct()
    {
    }
}

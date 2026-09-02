<?php

declare(strict_types=1);

namespace App\BulkImport\Infrastructure\Xlsx;

final class XlsxSecurityLimits
{
    public const MAXIMUM_FILE_BYTES = 5 * 1024 * 1024;
    public const MAXIMUM_ZIP_ENTRIES = 100;
    public const MAXIMUM_TOTAL_UNCOMPRESSED_BYTES = 25 * 1024 * 1024;
    public const MAXIMUM_ENTRY_UNCOMPRESSED_BYTES = 10 * 1024 * 1024;
    public const MAXIMUM_COMPRESSION_RATIO = 100;

    private function __construct()
    {
    }
}

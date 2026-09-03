<?php

declare(strict_types=1);

namespace App\BulkImport\Http;

use RuntimeException;

final readonly class BulkImportTemplateFile
{
    public function __construct(private string $path)
    {
    }

    public function content(): string
    {
        if (!is_file($this->path)) {
            throw new RuntimeException('Bulk Import template is unavailable.');
        }
        $content = file_get_contents($this->path);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Bulk Import template is unavailable.');
        }

        return $content;
    }
}

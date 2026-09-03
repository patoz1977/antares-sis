<?php

declare(strict_types=1);

namespace App\BulkImport\Http;

use RuntimeException;

final class BulkImportErrorCsvWriter
{
    /** @param list<array{category: string, sheet: string, row: int, field: ?string, message: string}> $issues */
    public function write(array $issues): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Bulk Import CSV is unavailable.');
        }

        try {
            $this->row($stream, ['category', 'sheet', 'row', 'field', 'message']);
            foreach ($issues as $issue) {
                $this->row($stream, [
                    $this->text((string) ($issue['category'] ?? '')),
                    $this->text((string) ($issue['sheet'] ?? '')),
                    is_int($issue['row'] ?? null) ? $issue['row'] : 0,
                    $this->text((string) ($issue['field'] ?? '')),
                    $this->text((string) ($issue['message'] ?? '')),
                ]);
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if (!is_string($content)) {
                throw new RuntimeException('Bulk Import CSV is unavailable.');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @param list<int|string> $values */
    private function row($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Bulk Import CSV is unavailable.');
        }
    }

    private function text(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '[valor no válido]';
        }
        $significant = ltrim($value, ' ');
        if ($significant !== '' && in_array($significant[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}

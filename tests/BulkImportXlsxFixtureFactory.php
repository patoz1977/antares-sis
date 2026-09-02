<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Application\BulkImportWorkbookContract;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class BulkImportXlsxFixtureFactory
{
    private string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-e015-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create XLSX fixture directory.');
        }
    }

    public function __destruct()
    {
        if (!is_dir($this->directory)) {
            return;
        }

        gc_collect_cycles();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $this->removeWithRetry(static fn (): bool => rmdir($item->getPathname()));
            } else {
                $this->removeWithRetry(static fn (): bool => unlink($item->getPathname()));
            }
        }
        $this->removeWithRetry(fn (): bool => rmdir($this->directory));
    }

    /**
     * @return array<string, list<list<null|bool|float|int|string>>>
     */
    public function validSheets(): array
    {
        return [
            'Familias' => [
                BulkImportWorkbookContract::HEADERS['Familias'],
                ['F00000001', 'Familia Uno'],
            ],
            'Representantes' => [
                BulkImportWorkbookContract::HEADERS['Representantes'],
                [
                    'F00000001', 'Ana', '', 'Pérez', '', '1985-02-03', 'female', 'dni',
                    '001234', 'ana@example.test', 'mother', 'SI', '2026-08-01', 'ClaveSegura9',
                ],
            ],
            'Estudiantes' => [
                BulkImportWorkbookContract::HEADERS['Estudiantes'],
                [
                    'F00000001', 'Luis', '', 'Pérez', '', '2015-04-05', 'male', '', '',
                    'EST-001', '2021-09-01', '2026-08-01',
                ],
            ],
        ];
    }

    /**
     * @param array<string, list<list<null|bool|float|int|string>>> $sheets
     * @param list<string> $hiddenSheets
     * @param list<array{sheet: int, firstColumn: int, firstRow: int, lastColumn: int, lastRow: int}> $merges
     */
    public function writeWorkbook(
        string $fileName,
        array $sheets,
        array $hiddenSheets = [],
        array $merges = [],
    ): string {
        $path = $this->path($fileName);
        $options = new Options();
        foreach ($merges as $merge) {
            $options->mergeCells(
                $merge['firstColumn'],
                $merge['firstRow'],
                $merge['lastColumn'],
                $merge['lastRow'],
                $merge['sheet'],
            );
        }

        $writer = new Writer($options);
        $writer->openToFile($path);
        try {
            $first = true;
            foreach ($sheets as $name => $rows) {
                $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $first = false;
                $sheet->setName($name);
                if (in_array($name, $hiddenSheets, true)) {
                    $sheet->setIsVisible(false);
                }
                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            }
        } finally {
            $writer->close();
        }

        $this->stripEmptyAnnotationParts($path, count($sheets));

        return $path;
    }

    public function writeBytes(string $fileName, string $contents): string
    {
        $path = $this->path($fileName);
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write hostile fixture.');
        }

        return $path;
    }

    public function writeOversizedFile(string $fileName): string
    {
        $path = $this->path($fileName);
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create oversized fixture.');
        }
        try {
            fwrite($stream, "PK\x03\x04");
            $remaining = (5 * 1024 * 1024) + 1 - 4;
            $chunk = str_repeat('X', 8192);
            while ($remaining > 0) {
                $written = fwrite($stream, substr($chunk, 0, min($remaining, strlen($chunk))));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to complete oversized fixture.');
                }
                $remaining -= $written;
            }
        } finally {
            fclose($stream);
        }

        return $path;
    }

    public function copyTemplate(string $fileName): string
    {
        $source = dirname(__DIR__) . '/resources/templates/bulk-import/e015-family-import-v1.xlsx';
        $target = $this->path($fileName);
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to copy template fixture.');
        }

        return $target;
    }

    /**
     * @param callable(ZipArchive): void $mutation
     */
    public function mutateZip(string $path, callable $mutation): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open ZIP fixture for mutation.');
        }
        try {
            $mutation($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param callable(string): string $mutation
     */
    public function mutateEntry(string $path, string $entry, callable $mutation): void
    {
        $this->mutateZip($path, static function (ZipArchive $zip) use ($entry, $mutation): void {
            $contents = $zip->getFromName($entry);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read ZIP fixture entry.');
            }
            if (!$zip->deleteName($entry) || !$zip->addFromString($entry, $mutation($contents))) {
                throw new RuntimeException('Unable to replace ZIP fixture entry.');
            }
        });
    }

    private function path(string $fileName): string
    {
        if (basename($fileName) !== $fileName) {
            throw new RuntimeException('Fixture filename must be a basename.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . $fileName;
    }

    private function stripEmptyAnnotationParts(string $path, int $sheetCount): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to sanitize XLSX fixture.');
        }
        try {
            for ($sheet = 1; $sheet <= $sheetCount; $sheet++) {
                $sheetEntry = 'xl/worksheets/sheet' . $sheet . '.xml';
                $xml = $zip->getFromName($sheetEntry);
                if (!is_string($xml)) {
                    throw new RuntimeException('Unable to read generated worksheet.');
                }
                $xml = preg_replace('/<legacyDrawing\b[^>]*\/>/', '', $xml);
                if (!is_string($xml)
                    || !$zip->deleteName($sheetEntry)
                    || !$zip->addFromString($sheetEntry, $xml)) {
                    throw new RuntimeException('Unable to sanitize generated worksheet.');
                }
                $zip->deleteName('xl/comments' . $sheet . '.xml');
                $zip->deleteName('xl/drawings/vmlDrawing' . $sheet . '.vml');
                $zip->deleteName('xl/worksheets/_rels/sheet' . $sheet . '.xml.rels');
            }

            $contentTypes = $zip->getFromName('[Content_Types].xml');
            if (!is_string($contentTypes)) {
                throw new RuntimeException('Unable to read generated content types.');
            }
            $contentTypes = preg_replace('/<Default Extension="vml"[^>]*\/>/', '', $contentTypes);
            $contentTypes = preg_replace('/<Override PartName="\/xl\/comments[0-9]+\.xml"[^>]*\/>/', '', (string) $contentTypes);
            if (!is_string($contentTypes)
                || !$zip->deleteName('[Content_Types].xml')
                || !$zip->addFromString('[Content_Types].xml', $contentTypes)) {
                throw new RuntimeException('Unable to sanitize generated content types.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param callable(): bool $operation
     */
    private function removeWithRetry(callable $operation): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@$operation()) {
                return;
            }
            gc_collect_cycles();
            usleep(10_000);
        }

        throw new RuntimeException('Unable to clean XLSX fixture artifact.');
    }
}

<?php

declare(strict_types=1);

namespace App\BulkImport\Infrastructure\Xlsx;

use App\BulkImport\Application\BulkImportWorkbookContract;
use App\BulkImport\Application\Exception\BulkImportWorkbookRejected;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

final class XlsxContainerPreflightInspector
{
    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const OFFICE_RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function inspect(string $localPath): void
    {
        $this->assertLocalFile($localPath);
        $zip = new ZipArchive();
        $opened = $zip->open($localPath, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new BulkImportWorkbookRejected('El archivo no es un contenedor XLSX válido.');
        }

        try {
            $entryNames = $this->inspectEntries($zip);
            $this->assertMinimumStructure($entryNames);
            $this->inspectRelationships($zip, $entryNames);
            $sheetPaths = $this->inspectWorkbook($zip);
            $this->inspectWorksheets($zip, $sheetPaths);
        } finally {
            $zip->close();
        }
    }

    private function assertLocalFile(string $localPath): void
    {
        if ($localPath === ''
            || str_contains($localPath, '://')
            || is_link($localPath)
            || !is_file($localPath)
            || !is_readable($localPath)) {
            throw new BulkImportWorkbookRejected('El archivo XLSX local no existe o no es legible.');
        }

        $size = filesize($localPath);
        if ($size === false || $size <= 0 || $size > XlsxSecurityLimits::MAXIMUM_FILE_BYTES) {
            throw new BulkImportWorkbookRejected('El archivo XLSX debe tener entre 1 byte y 5 MiB.');
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new BulkImportWorkbookRejected('El archivo XLSX no pudo abrirse de forma segura.');
        }
        try {
            $signature = fread($stream, 4);
        } finally {
            fclose($stream);
        }

        if ($signature !== "PK\x03\x04") {
            throw new BulkImportWorkbookRejected('El archivo no contiene la firma ZIP requerida por XLSX.');
        }
    }

    /**
     * @return array<string, true>
     */
    private function inspectEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > XlsxSecurityLimits::MAXIMUM_ZIP_ENTRIES) {
            throw new BulkImportWorkbookRejected('El contenedor XLSX excede el límite de 100 entradas.');
        }

        $names = [];
        $declaredCompressed = 0;
        $declaredUncompressed = 0;
        $observedUncompressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if ($stat === false
                || !isset($stat['name'], $stat['size'], $stat['comp_size'])
                || !is_string($stat['name'])
                || !is_int($stat['size'])
                || !is_int($stat['comp_size'])
                || $stat['size'] < 0
                || $stat['comp_size'] < 0) {
                throw new BulkImportWorkbookRejected('El contenedor XLSX contiene metadata ZIP incoherente.');
            }

            $name = $stat['name'];
            $this->assertSafeEntryName($name);
            if (isset($names[$name])) {
                throw new BulkImportWorkbookRejected('El contenedor XLSX contiene entradas duplicadas.');
            }
            $names[$name] = true;

            if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                throw new BulkImportWorkbookRejected('El contenedor XLSX no puede contener entradas cifradas.');
            }

            if ($stat['size'] > XlsxSecurityLimits::MAXIMUM_ENTRY_UNCOMPRESSED_BYTES) {
                throw new BulkImportWorkbookRejected('Una entrada XLSX excede 10 MiB descomprimidos.');
            }

            $this->assertCompressionRatio($stat['size'], $stat['comp_size']);
            $declaredCompressed += $stat['comp_size'];
            $declaredUncompressed += $stat['size'];
            if ($declaredUncompressed > XlsxSecurityLimits::MAXIMUM_TOTAL_UNCOMPRESSED_BYTES) {
                throw new BulkImportWorkbookRejected('El contenedor XLSX excede 25 MiB descomprimidos.');
            }

            $lowerName = strtolower($name);
            if (str_starts_with($lowerName, 'xl/embeddings/')
                || str_starts_with($lowerName, 'xl/activex/')
                || str_starts_with($lowerName, 'xl/externalLinks/')
                || str_starts_with($lowerName, 'xl/ctrlprops/')
                || str_starts_with($lowerName, 'xl/comments')
                || str_starts_with($lowerName, 'xl/drawings/')
                || str_contains($lowerName, 'vbaproject')
                || str_ends_with($lowerName, '.exe')
                || str_ends_with($lowerName, '.dll')) {
                throw new BulkImportWorkbookRejected('El XLSX contiene macros, enlaces u objetos embebidos no permitidos.');
            }

            if (!str_ends_with($name, '/')) {
                $observedUncompressed += $this->observeEntryBytes($zip, $name, $stat['size']);
                if ($observedUncompressed > XlsxSecurityLimits::MAXIMUM_TOTAL_UNCOMPRESSED_BYTES) {
                    throw new BulkImportWorkbookRejected('El contenido XLSX observado excede el límite descomprimido.');
                }
            }
        }

        $this->assertCompressionRatio($declaredUncompressed, $declaredCompressed);

        return $names;
    }

    private function assertSafeEntryName(string $name): void
    {
        if ($name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/\A[A-Za-z]:/', $name) === 1) {
            throw new BulkImportWorkbookRejected('El contenedor XLSX contiene una ruta insegura.');
        }

        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new BulkImportWorkbookRejected('El contenedor XLSX contiene traversal de rutas.');
            }
        }
    }

    private function assertCompressionRatio(int $uncompressed, int $compressed): void
    {
        if ($uncompressed === 0) {
            return;
        }
        if ($compressed <= 0
            || ($uncompressed / $compressed) > XlsxSecurityLimits::MAXIMUM_COMPRESSION_RATIO) {
            throw new BulkImportWorkbookRejected('El contenedor XLSX excede el ratio máximo de compresión.');
        }
    }

    private function observeEntryBytes(ZipArchive $zip, string $name, int $declaredSize): int
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new BulkImportWorkbookRejected('Una entrada XLSX no pudo leerse de forma segura.');
        }

        $observed = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new BulkImportWorkbookRejected('Una entrada XLSX produjo un error de lectura.');
                }
                $observed += strlen($chunk);
                if ($observed > $declaredSize
                    || $observed > XlsxSecurityLimits::MAXIMUM_ENTRY_UNCOMPRESSED_BYTES) {
                    throw new BulkImportWorkbookRejected('Una entrada XLSX no coincide con sus límites declarados.');
                }
            }
        } finally {
            fclose($stream);
        }

        if ($observed !== $declaredSize) {
            throw new BulkImportWorkbookRejected('Una entrada XLSX tiene un tamaño observado incoherente.');
        }

        return $observed;
    }

    /**
     * @param array<string, true> $entryNames
     */
    private function assertMinimumStructure(array $entryNames): void
    {
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
            if (!isset($entryNames[$required])) {
                throw new BulkImportWorkbookRejected('El contenedor no contiene la estructura OOXML mínima requerida.');
            }
        }
    }

    /**
     * @param array<string, true> $entryNames
     */
    private function inspectRelationships(ZipArchive $zip, array $entryNames): void
    {
        foreach (array_keys($entryNames) as $name) {
            if (!str_ends_with(strtolower($name), '.rels')) {
                continue;
            }

            $document = $this->loadXmlEntry($zip, $name);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('r', self::RELATIONSHIPS_NAMESPACE);
            foreach ($xpath->query('/r:Relationships/r:Relationship') ?: [] as $relationship) {
                if (!$relationship instanceof DOMElement) {
                    continue;
                }
                if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) {
                    throw new BulkImportWorkbookRejected('El XLSX contiene una relación externa no permitida.');
                }
                $type = strtolower($relationship->getAttribute('Type'));
                if (str_contains($type, 'externallink')
                    || str_contains($type, 'oleobject')
                    || str_contains($type, 'activex')
                    || str_contains($type, 'comments')
                    || str_contains($type, 'vmldrawing')
                    || str_contains($type, 'vbaproject')) {
                    throw new BulkImportWorkbookRejected('El XLSX contiene una relación de contenido no permitido.');
                }
            }
        }

        $rootRelationships = $this->loadXmlEntry($zip, '_rels/.rels');
        $rootXPath = new DOMXPath($rootRelationships);
        $rootXPath->registerNamespace('r', self::RELATIONSHIPS_NAMESPACE);
        $hasWorkbookRelationship = false;
        foreach ($rootXPath->query('/r:Relationships/r:Relationship') ?: [] as $relationship) {
            if ($relationship instanceof DOMElement
                && str_ends_with($relationship->getAttribute('Type'), '/officeDocument')
                && ltrim($relationship->getAttribute('Target'), '/') === 'xl/workbook.xml') {
                $hasWorkbookRelationship = true;
            }
        }
        if (!$hasWorkbookRelationship) {
            throw new BulkImportWorkbookRejected('El XLSX no declara la relación principal del workbook.');
        }

        $contentTypes = strtolower($this->readXmlEntry($zip, '[Content_Types].xml'));
        if (str_contains($contentTypes, 'macroenabled')
            || str_contains($contentTypes, 'vbaproject')
            || str_contains($contentTypes, 'oleobject')) {
            throw new BulkImportWorkbookRejected('El XLSX declara contenido ejecutable o embebido no permitido.');
        }
        if (!str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')
            || !str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml')) {
            throw new BulkImportWorkbookRejected('El XLSX no declara los tipos OOXML requeridos.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function inspectWorkbook(ZipArchive $zip): array
    {
        $relationships = $this->loadXmlEntry($zip, 'xl/_rels/workbook.xml.rels');
        $relationshipXPath = new DOMXPath($relationships);
        $relationshipXPath->registerNamespace('r', self::RELATIONSHIPS_NAMESPACE);
        $targets = [];
        foreach ($relationshipXPath->query('/r:Relationships/r:Relationship') ?: [] as $relationship) {
            if (!$relationship instanceof DOMElement) {
                continue;
            }
            $id = $relationship->getAttribute('Id');
            $type = $relationship->getAttribute('Type');
            if ($id !== '' && str_ends_with($type, '/worksheet')) {
                $targets[$id] = $this->normalizeInternalTarget('xl', $relationship->getAttribute('Target'));
            }
        }

        $workbook = $this->loadXmlEntry($zip, 'xl/workbook.xml');
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
        $xpath->registerNamespace('od', self::OFFICE_RELATIONSHIPS_NAMESPACE);
        $sheetPaths = [];
        foreach ($xpath->query('/s:workbook/s:sheets/s:sheet') ?: [] as $sheet) {
            if (!$sheet instanceof DOMElement) {
                continue;
            }
            $name = $sheet->getAttribute('name');
            $state = $sheet->getAttribute('state');
            $relationshipId = $sheet->getAttributeNS(self::OFFICE_RELATIONSHIPS_NAMESPACE, 'id');
            if ($name === '' || isset($sheetPaths[$name]) || !isset($targets[$relationshipId])) {
                throw new BulkImportWorkbookRejected('El workbook contiene hojas duplicadas o relaciones inválidas.');
            }
            if ($state !== '' && strcasecmp($state, 'visible') !== 0) {
                throw new BulkImportWorkbookRejected('El workbook no puede contener hojas ocultas.');
            }
            $sheetPaths[$name] = $targets[$relationshipId];
        }

        if (count(array_unique(array_values($sheetPaths), SORT_STRING)) !== count($sheetPaths)) {
            throw new BulkImportWorkbookRejected('El workbook contiene hojas físicas duplicadas.');
        }

        $actual = array_keys($sheetPaths);
        $expected = array_keys(BulkImportWorkbookContract::HEADERS);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new BulkImportWorkbookRejected('El workbook debe contener exactamente Familias, Representantes y Estudiantes.');
        }

        return $sheetPaths;
    }

    /**
     * @param array<string, string> $sheetPaths
     */
    private function inspectWorksheets(ZipArchive $zip, array $sheetPaths): void
    {
        foreach ($sheetPaths as $path) {
            if ($zip->locateName($path, ZipArchive::FL_UNCHANGED) === false) {
                throw new BulkImportWorkbookRejected('Una hoja declarada no existe físicamente en el XLSX.');
            }

            $document = $this->loadXmlEntry($zip, $path);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
            if (($xpath->query('//s:f')?->length ?? 0) > 0) {
                throw new BulkImportWorkbookRejected('El workbook contiene fórmulas no permitidas.');
            }
            if (($xpath->query('//s:mergeCell')?->length ?? 0) > 0) {
                throw new BulkImportWorkbookRejected('El workbook contiene celdas combinadas no permitidas.');
            }
            if (($xpath->query('//s:col[@hidden="1" or @hidden="true"]')?->length ?? 0) > 0) {
                throw new BulkImportWorkbookRejected('El workbook contiene columnas ocultas no permitidas.');
            }
            if (($xpath->query('//s:row[@hidden="1" or @hidden="true"]')?->length ?? 0) > 0) {
                throw new BulkImportWorkbookRejected('El workbook contiene filas ocultas no permitidas.');
            }
        }
    }

    private function normalizeInternalTarget(string $baseDirectory, string $target): string
    {
        if ($target === '' || str_contains($target, '\\') || preg_match('/\A[A-Za-z]+:/', $target) === 1) {
            throw new BulkImportWorkbookRejected('El XLSX contiene una relación interna insegura.');
        }

        $parts = [];
        $combined = str_starts_with($target, '/')
            ? ltrim($target, '/')
            : $baseDirectory . '/' . $target;
        foreach (explode('/', $combined) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new BulkImportWorkbookRejected('El XLSX contiene traversal en una relación interna.');
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        $normalized = implode('/', $parts);
        if (!str_starts_with($normalized, 'xl/worksheets/')) {
            throw new BulkImportWorkbookRejected('Una hoja apunta fuera de la ubicación OOXML esperada.');
        }

        return $normalized;
    }

    private function loadXmlEntry(ZipArchive $zip, string $name): DOMDocument
    {
        $xml = $this->readXmlEntry($zip, $name);
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new BulkImportWorkbookRejected('El XLSX contiene declaraciones XML no permitidas.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            throw new BulkImportWorkbookRejected('El XLSX contiene XML relevante inválido.');
        }

        return $document;
    }

    private function readXmlEntry(ZipArchive $zip, string $name): string
    {
        $contents = $zip->getFromName($name, 0, ZipArchive::FL_UNCHANGED);
        if (!is_string($contents) || $contents === '') {
            throw new BulkImportWorkbookRejected('Una parte XML requerida no pudo leerse.');
        }

        return $contents;
    }
}

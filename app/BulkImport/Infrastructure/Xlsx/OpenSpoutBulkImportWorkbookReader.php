<?php

declare(strict_types=1);

namespace App\BulkImport\Infrastructure\Xlsx;

use App\BulkImport\Application\BulkImportWorkbookContract;
use App\BulkImport\Application\Contract\BulkImportWorkbookReader;
use App\BulkImport\Application\Dto\RawWorkbook;
use App\BulkImport\Application\Dto\RawWorkbookCell;
use App\BulkImport\Application\Dto\RawWorkbookCellType;
use App\BulkImport\Application\Dto\RawWorkbookRow;
use App\BulkImport\Application\Dto\RawWorkbookSheet;
use App\BulkImport\Application\Dto\WorkbookValidationResult;
use App\BulkImport\Application\Exception\BulkImportWorkbookRejected;
use App\BulkImport\Application\ValidateBulkImportWorkbook;
use DateTimeImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

final readonly class OpenSpoutBulkImportWorkbookReader implements BulkImportWorkbookReader
{
    public function __construct(
        private XlsxContainerPreflightInspector $preflightInspector,
        private ValidateBulkImportWorkbook $validator,
    ) {
    }

    public function read(string $localPath, DateTimeImmutable $today): WorkbookValidationResult
    {
        $this->preflightInspector->inspect($localPath);

        $options = new Options();
        $options->SHOULD_FORMAT_DATES = false;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_LOAD_MERGE_CELLS = false;
        $reader = new Reader($options);
        $opened = false;

        try {
            $reader->open($localPath);
            $opened = true;
            $sheets = [];
            $totalDataRows = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $sheet->getName();
                if (!array_key_exists($name, BulkImportWorkbookContract::HEADERS)) {
                    throw new BulkImportWorkbookRejected('El workbook contiene una hoja no permitida.');
                }

                $rows = [];
                $dataRows = 0;
                foreach ($sheet->getRowIterator() as $physicalRow => $row) {
                    $cells = [];
                    for ($index = 0; $index < $row->getNumCells(); $index++) {
                        $cell = $row->getCellAtIndex($index);
                        $cells[] = $cell === null
                            ? new RawWorkbookCell(RawWorkbookCellType::Empty, null)
                            : $this->mapCell($cell);
                    }
                    $expectedColumns = count(BulkImportWorkbookContract::HEADERS[$name]);
                    while (count($cells) < $expectedColumns) {
                        $cells[] = new RawWorkbookCell(RawWorkbookCellType::Empty, null);
                    }

                    $rawRow = new RawWorkbookRow((int) $physicalRow, $cells);
                    $rows[] = $rawRow;
                    if ($physicalRow > 1 && !$rawRow->isBlank()) {
                        $dataRows++;
                        $totalDataRows++;
                        if ($dataRows > BulkImportWorkbookContract::MAXIMUM_DATA_ROWS[$name]) {
                            throw new BulkImportWorkbookRejected('Una hoja excede el límite aprobado de filas de datos.');
                        }
                        if ($totalDataRows > BulkImportWorkbookContract::MAXIMUM_TOTAL_ROWS) {
                            throw new BulkImportWorkbookRejected('El workbook excede 5000 filas de datos combinadas.');
                        }
                    }
                }

                $sheets[$name] = new RawWorkbookSheet($name, $rows);
            }

            return $this->validator->validate(new RawWorkbook($sheets), $today);
        } catch (BulkImportWorkbookRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BulkImportWorkbookRejected('El workbook XLSX no pudo procesarse de forma segura.');
        } finally {
            if ($opened) {
                $reader->close();
            }
        }
    }

    private function mapCell(Cell $cell): RawWorkbookCell
    {
        $type = match (true) {
            $cell instanceof Cell\EmptyCell => RawWorkbookCellType::Empty,
            $cell instanceof Cell\StringCell => RawWorkbookCellType::String,
            $cell instanceof Cell\NumericCell => RawWorkbookCellType::Numeric,
            $cell instanceof Cell\BooleanCell => RawWorkbookCellType::Boolean,
            $cell instanceof Cell\DateTimeCell,
            $cell instanceof Cell\DateIntervalCell => RawWorkbookCellType::Date,
            // The preflight rejects every physical <f> node. OpenSpout 4.28.5
            // also creates FormulaCell for an unequivocal inline string that
            // merely starts with "=", so the remaining case is safe text.
            $cell instanceof Cell\FormulaCell => RawWorkbookCellType::String,
            default => RawWorkbookCellType::Error,
        };
        $value = $cell->getValue();

        if (!$value instanceof DateTimeInterface
            && !is_string($value)
            && !is_int($value)
            && !is_float($value)
            && !is_bool($value)
            && $value !== null) {
            return new RawWorkbookCell(RawWorkbookCellType::Error, null);
        }

        return new RawWorkbookCell($type, $value);
    }
}

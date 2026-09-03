<?php

declare(strict_types=1);

namespace App\BulkImport\Http;

use Throwable;

final readonly class BulkImportTemplateController
{
    public function __construct(private BulkImportTemplateFile $template)
    {
    }

    public function download(): string
    {
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        try {
            $content = $this->template->content();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="plantilla-importacion-familias.xlsx"');
            header('Content-Length: ' . strlen($content));
            http_response_code(200);

            return $content;
        } catch (Throwable) {
            http_response_code(500);

            return '<h1>Plantilla no disponible</h1>'
                . '<p role="alert">La plantilla oficial no pudo descargarse.</p>';
        }
    }
}

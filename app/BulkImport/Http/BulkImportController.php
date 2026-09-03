<?php

declare(strict_types=1);

namespace App\BulkImport\Http;

use App\BulkImport\Application\Delivery\BulkImportDeliverySession;
use App\BulkImport\Application\Dto\PreviewBulkImportResult;
use App\BulkImport\Application\Exception\BulkImportWorkbookRejected;
use App\BulkImport\Application\PreviewBulkImport;
use App\Family\Http\FamilyFormOptionsProvider;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\Person\Http\PersonFormOptionsProvider;
use App\Controllers\Controller;
use Core\Http\Request;
use Throwable;

final class BulkImportController extends Controller
{
    public function __construct(
        private readonly PreviewBulkImport $preview,
        private readonly BulkImportTemporaryFileStore $temporaryFiles,
        private readonly BulkImportDeliverySession $workflow,
        private readonly BulkImportErrorCsvWriter $csv,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly PersonFormOptionsProvider $personOptions,
        private readonly FamilyFormOptionsProvider $familyOptions,
    ) {
    }

    public function index(): string
    {
        return $this->renderPage();
    }

    public function preview(): string
    {
        $request = new Request();
        $input = $request->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->renderPage(
                errorMessage: 'La solicitud no pudo verificarse.',
                status: 403,
            );
        }

        $actorId = $this->session->authenticatedUserId();
        if ($actorId === null) {
            return $this->plainError('La sesión administrativa no está disponible.', 403);
        }

        $this->workflow->invalidateForNewPreview();
        if (!$this->containsOnly($input, ['_csrf_token'])) {
            return $this->renderPage(
                errorMessage: 'La solicitud de vista previa no es válida.',
                status: 422,
            );
        }

        $localPath = null;
        try {
            $upload = $request->files()['workbook'] ?? null;
            $localPath = $this->temporaryFiles->store(is_array($upload) ? $upload : []);
            $digest = hash_file('sha256', $localPath);
            if (!is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                throw new BulkImportUploadRejected('El archivo no pudo verificarse de forma segura.');
            }

            $result = $this->preview->handle($localPath);
            $safe = $result->safeOutput();
            $this->workflow->storeReport($actorId, $safe['issues']);
            $token = $result->families > 0
                ? $this->workflow->issuePreview($actorId, $digest, $safe['counts'])
                : null;

            return $this->renderPage(
                preview: $safe,
                previewToken: $token,
                status: $result->issues === [] ? 200 : 422,
            );
        } catch (BulkImportUploadRejected | BulkImportWorkbookRejected $exception) {
            $issues = [$this->safeIssue('FILE_INVALID', $exception->getMessage())];
            $this->workflow->storeReport($actorId, $issues);

            return $this->renderPage(
                preview: $this->emptyPreview($issues),
                errorMessage: $exception->getMessage(),
                status: 422,
            );
        } catch (Throwable) {
            $issues = [$this->safeIssue(
                'FILE_INVALID',
                'El archivo no pudo procesarse de forma segura.',
            )];
            $this->workflow->storeReport($actorId, $issues);

            return $this->renderPage(
                preview: $this->emptyPreview($issues),
                errorMessage: 'El archivo no pudo procesarse de forma segura.',
                status: 500,
            );
        } finally {
            if (is_string($localPath)) {
                $this->temporaryFiles->delete($localPath);
            }
        }
    }

    public function result(): string
    {
        $actorId = $this->session->authenticatedUserId();
        if ($actorId === null) {
            return $this->plainError('La sesión administrativa no está disponible.', 403);
        }
        $result = $this->workflow->pullResult($actorId);
        $status = $result === null ? 404 : 200;
        http_response_code($status);
        $this->noStore();

        return $this->view('bulk-import.result', [
            'title' => 'Resultado de importación masiva',
            'result' => $result,
            'hasReport' => $this->workflow->report($actorId) !== null,
        ]);
    }

    public function errorsCsv(): string
    {
        $actorId = $this->session->authenticatedUserId();
        if ($actorId === null) {
            return $this->plainError('La sesión administrativa no está disponible.', 403);
        }
        $issues = $this->workflow->report($actorId);
        if ($issues === null) {
            return $this->plainError('No existe un reporte vigente para descargar.', 404);
        }

        try {
            $content = $this->csv->write($issues);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="errores-importacion-masiva.csv"');
            header('Content-Length: ' . strlen($content));
            header('X-Content-Type-Options: nosniff');
            $this->noStore();
            http_response_code(200);

            return $content;
        } catch (Throwable) {
            return $this->plainError('El reporte no pudo generarse.', 500);
        }
    }

    /** @param array<string, mixed>|null $preview */
    private function renderPage(
        ?array $preview = null,
        ?string $previewToken = null,
        ?string $errorMessage = null,
        int $status = 200,
    ): string {
        try {
            $personOptions = $this->personOptions->get();
            $familyOptions = $this->familyOptions->get();
        } catch (Throwable) {
            return $this->plainError('La importación masiva no está disponible.', 500);
        }

        http_response_code($status);
        $this->noStore();

        return $this->view('bulk-import.index', [
            'title' => 'Importación masiva',
            'csrfToken' => $this->csrf->token(),
            'preview' => $preview,
            'previewToken' => $previewToken,
            'errorMessage' => $errorMessage,
            'documentTypes' => $this->safeOptions($personOptions->documentTypes),
            'sexes' => $this->safeOptions($personOptions->sexes),
            'relationshipTypes' => $this->safeOptions($familyOptions->relationshipTypes),
        ]);
    }

    /** @param list<object> $options @return list<array{code: string, name: string}> */
    private function safeOptions(array $options): array
    {
        return array_map(
            static fn (object $option): array => [
                'code' => (string) $option->code,
                'name' => (string) $option->name,
            ],
            $options,
        );
    }

    /** @param list<array<string, int|string|null>> $issues @return array<string, mixed> */
    private function emptyPreview(array $issues): array
    {
        return [
            'counts' => [
                'families' => 0,
                'new' => 0,
                'already_exists' => 0,
                'conflicts' => 0,
                'issues' => count($issues),
            ],
            'items' => [],
            'issues' => $issues,
        ];
    }

    /** @return array{category: string, sheet: string, row: int, field: null, message: string} */
    private function safeIssue(string $category, string $message): array
    {
        return [
            'category' => $category,
            'sheet' => 'Workbook',
            'row' => 0,
            'field' => null,
            'message' => $message,
        ];
    }

    /** @param array<mixed> $input @param list<string> $allowed */
    private function containsOnly(array $input, array $allowed): bool
    {
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        sort($allowed, SORT_STRING);

        return $keys === $allowed;
    }

    /** @param array<mixed> $input */
    private function scalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function plainError(string $message, int $status): string
    {
        http_response_code($status);
        $this->noStore();

        return '<h1>Importación masiva no disponible</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/admin/bulk-import">Volver a importación masiva</a></p>';
    }

    private function noStore(): void
    {
        header('Cache-Control: no-store');
    }
}

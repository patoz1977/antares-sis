<?php

declare(strict_types=1);

namespace App\BulkImport\Http;

use App\BulkImport\Application\ApplyBulkImport;
use App\BulkImport\Application\Dto\ApplyBulkImportResult;
use App\BulkImport\Application\Dto\ApplyFamilyResult;
use App\BulkImport\Application\Dto\ValidationIssue;
use App\BulkImport\Application\Delivery\BulkImportDeliverySession;
use App\BulkImport\Application\Exception\BulkImportWorkbookRejected;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use Core\Http\Request;
use Throwable;

final readonly class BulkImportApplyController
{
    public function __construct(
        private ApplyBulkImport $apply,
        private BulkImportTemporaryFileStore $temporaryFiles,
        private BulkImportDeliverySession $workflow,
        private CsrfTokenManager $csrf,
        private SessionManager $session,
        private Clock $clock,
    ) {
    }

    public function apply(): string
    {
        $request = new Request();
        $input = $request->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->failWithoutToken('La solicitud no pudo verificarse.', 403);
        }

        $actorId = $this->session->authenticatedUserId();
        if ($actorId === null) {
            return $this->failWithoutToken('La sesión administrativa no está disponible.', 403);
        }

        $grant = $this->workflow->consumePreview(
            $this->scalar($input, 'preview_token'),
            $actorId,
        );
        if ($grant === null) {
            $this->storeFailure(
                $actorId,
                'CONCURRENT_CHANGE',
                'La vista previa ya no es válida. Realice una nueva validación del archivo.',
            );

            return $this->redirectToResult();
        }
        if (!$this->containsOnly($input, ['_csrf_token', 'preview_token'])) {
            $this->storeFailure(
                $actorId,
                'FILE_INVALID',
                'La solicitud de aplicación no es válida.',
            );

            return $this->redirectToResult();
        }

        $localPath = null;
        try {
            $upload = $request->files()['workbook'] ?? null;
            $localPath = $this->temporaryFiles->store(is_array($upload) ? $upload : []);
            $digest = hash_file('sha256', $localPath);
            if (!is_string($digest) || !hash_equals($grant->digest, $digest)) {
                $this->storeFailure(
                    $actorId,
                    'FILE_INVALID',
                    'El archivo no coincide con el que fue previamente validado. Realice una nueva vista previa.',
                );

                return $this->redirectToResult();
            }

            $result = $this->apply->handle($localPath, $this->clock->now());
            $this->workflow->storeResult(
                $actorId,
                array_map($this->family(...), $result->families),
                $this->issues($result),
            );

            return $this->redirectToResult();
        } catch (BulkImportUploadRejected | BulkImportWorkbookRejected $exception) {
            $this->storeFailure($actorId, 'FILE_INVALID', $exception->getMessage());

            return $this->redirectToResult();
        } catch (Throwable) {
            $this->storeFailure(
                $actorId,
                'APPLY_FAILED',
                'La importación no pudo completarse de forma segura.',
            );

            return $this->redirectToResult();
        } finally {
            if (is_string($localPath)) {
                $this->temporaryFiles->delete($localPath);
            }
        }
    }

    /** @return array{family_code: string, classification: string, label: string, message: string} */
    private function family(ApplyFamilyResult $result): array
    {
        $label = match ($result->classification->value) {
            'NEW' => 'Aplicado',
            'ALREADY_EXISTS' => 'Sin cambios',
            default => 'Conflicto',
        };

        return [
            'family_code' => $result->familyCode,
            'classification' => $result->classification->value,
            'label' => $label,
            'message' => $result->message,
        ];
    }

    /** @return list<array{category: string, sheet: string, row: int, field: ?string, message: string}> */
    private function issues(ApplyBulkImportResult $result): array
    {
        $issues = array_map(
            static fn (ValidationIssue $issue): array => $issue->safeOutput(),
            $result->issues,
        );
        foreach ($result->families as $family) {
            foreach ($family->issues as $issue) {
                $issues[] = $issue->safeOutput();
            }
        }

        return $issues;
    }

    private function storeFailure(int $actorId, string $category, string $message): void
    {
        $this->workflow->storeResult($actorId, [], [[
            'category' => $category,
            'sheet' => 'Workbook',
            'row' => 0,
            'field' => null,
            'message' => $message,
        ]]);
    }

    private function failWithoutToken(string $message, int $status): string
    {
        http_response_code($status);
        header('Cache-Control: no-store');

        return '<h1>Importación masiva no disponible</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    private function redirectToResult(): string
    {
        header('Location: /admin/bulk-import/result');
        http_response_code(303);

        return '';
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
}

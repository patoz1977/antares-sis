<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Enrollment\Application\AcademicInitialization\ApplyAcademicInitialization;
use App\Enrollment\Application\AcademicInitialization\Exception\AcademicInitializationRejected;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use Core\Http\Request;
use Throwable;

final readonly class AcademicInitializationApplyController
{
    public function __construct(
        private ApplyAcademicInitialization $apply,
        private AcademicInitializationTemporaryFileStore $temporaryFiles,
        private AcademicInitializationDeliverySession $workflow,
        private CsrfTokenManager $csrf,
        private SessionManager $session,
    ) {
    }

    public function apply(): string
    {
        $request = new Request();
        $input = $request->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->plainError('La solicitud no pudo verificarse.', 403);
        }
        $actorId = $this->session->authenticatedUserId();
        if ($actorId === null) {
            return $this->plainError('La sesión administrativa no está disponible.', 403);
        }

        $grant = $this->workflow->consumePreview(
            $this->scalar($input, 'preview_token'),
            $actorId,
        );
        if ($grant === null) {
            $this->failure(
                $actorId,
                'PREVIEW_EXPIRED',
                'La vista previa venció o ya fue utilizada. Ejecute un nuevo preflight.',
            );

            return $this->redirectToResult();
        }
        if (!$this->containsOnly($input, ['_csrf_token', 'preview_token'])) {
            $this->failure($actorId, 'REQUEST_INVALID', 'La solicitud de Apply no es válida.');

            return $this->redirectToResult();
        }

        $localPath = null;
        try {
            $upload = $request->files()['manifest'] ?? null;
            $localPath = $this->temporaryFiles->store(is_array($upload) ? $upload : []);
            $digest = hash_file('sha256', $localPath);
            if (!is_string($digest) || !hash_equals($grant->fileDigest, $digest)) {
                $this->failure(
                    $actorId,
                    'FILE_MISMATCH',
                    'El manifest no coincide con el validado. Ejecute un nuevo preflight.',
                );

                return $this->redirectToResult();
            }

            $result = $this->apply->handle(
                $localPath,
                $grant->academicPeriodCode,
                $grant->stateDigest,
            );
            $this->workflow->storeResult($actorId, $result->safeOutput(), []);

            return $this->redirectToResult();
        } catch (AcademicInitializationUploadRejected $exception) {
            $this->failure($actorId, 'FILE_INVALID', $exception->getMessage());

            return $this->redirectToResult();
        } catch (AcademicInitializationRejected) {
            $this->failure(
                $actorId,
                'CONCURRENT_CHANGE',
                'El estado cambió o el lote contiene un conflicto. No se aplicó ninguna fila.',
            );

            return $this->redirectToResult();
        } catch (Throwable) {
            $this->failure(
                $actorId,
                'APPLY_FAILED',
                'La inicialización no pudo completarse y fue revertida completamente.',
            );

            return $this->redirectToResult();
        } finally {
            if (is_string($localPath)) {
                $this->temporaryFiles->delete($localPath);
            }
        }
    }

    private function failure(int $actorId, string $category, string $message): void
    {
        $this->workflow->storeResult($actorId, null, [[
            'category' => $category,
            'row' => 0,
            'field' => null,
            'message' => $message,
        ]]);
    }

    private function redirectToResult(): string
    {
        header('Location: /admin/academic-initialization/result');
        http_response_code(303);

        return '';
    }

    private function plainError(string $message, int $status): string
    {
        http_response_code($status);
        header('Cache-Control: no-store');

        return '<h1>Inicialización académica no disponible</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p>';
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

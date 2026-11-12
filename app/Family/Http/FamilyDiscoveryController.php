<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Controllers\Controller;
use App\Family\Application\Discovery\Dto\FamilyDiscoveryResult;
use App\Family\Application\Discovery\Exception\InvalidFamilyDiscoveryCriteria;
use App\Family\Application\Discovery\FamilyDirectoryCriteria;
use App\Family\Application\Discovery\FamilyDirectoryStatus;
use App\Family\Application\Discovery\FamilyDiscoveryCriteria;
use App\Family\Application\Discovery\SearchFamilies;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use Core\Http\Request;
use Throwable;

final class FamilyDiscoveryController extends Controller
{
    public function __construct(
        private readonly SearchFamilies $searchFamilies,
        private readonly CsrfTokenManager $csrf,
    ) {
    }

    public function search(): string
    {
        $this->preventCaching();
        $input = (new Request())->input();
        $field = $this->scalar($input, 'criterion');
        $value = $this->scalar($input, 'value');
        $status = $this->scalar($input, 'status');

        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->viewResult(
                $field,
                $value,
                $status,
                ['No se pudo verificar la solicitud.'],
                null,
                422,
            );
        }

        try {
            $criteria = $value === '' ? null : new FamilyDiscoveryCriteria($field, $value);
            $directoryStatus = FamilyDirectoryStatus::fromInput($status);
            $result = $this->searchFamilies->handle(new FamilyDirectoryCriteria(
                $directoryStatus,
                $criteria,
            ));
        } catch (InvalidFamilyDiscoveryCriteria $exception) {
            return $this->viewResult($field, $value, $status, [$exception->getMessage()], null, 422);
        } catch (Throwable) {
            return $this->viewResult(
                '',
                '',
                FamilyDirectoryStatus::Active->value,
                ['No se pudo completar la búsqueda. Inténtalo de nuevo.'],
                null,
                500,
            );
        }

        return $this->viewResult(
            $criteria?->field->value ?? $field,
            $criteria?->value ?? '',
            $directoryStatus->value,
            [],
            $result,
        );
    }

    /** @param list<string> $errors */
    private function viewResult(
        string $criterion,
        string $value,
        string $statusFilter,
        array $errors,
        ?FamilyDiscoveryResult $result,
        int $status = 200,
    ): string {
        http_response_code($status);

        return $this->view('families.index', [
            'title' => 'Familias',
            'successMessage' => null,
            'errorMessage' => null,
            'csrfToken' => $this->csrf->token(),
            'criterion' => $criterion,
            'searchValue' => $value,
            'directoryStatus' => $statusFilter,
            'searchErrors' => $errors,
            'searchResult' => $result,
        ]);
    }

    private function scalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function preventCaching(): void
    {
        header('Cache-Control: no-store');
    }
}

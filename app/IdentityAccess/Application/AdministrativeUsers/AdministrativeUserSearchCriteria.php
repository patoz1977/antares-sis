<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\InvalidAdministrativeUserSearch;
use App\IdentityAccess\Domain\Exception\InvalidUserState;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;

final readonly class AdministrativeUserSearchCriteria
{
    public AdministrativeUserSearchField $field;
    public string $value;

    public function __construct(string $field, string $value)
    {
        $resolved = AdministrativeUserSearchField::tryFrom(trim($field));
        if ($resolved === null) {
            throw new InvalidAdministrativeUserSearch('Selecciona un criterio de búsqueda válido.');
        }

        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidAdministrativeUserSearch('Ingresa un valor para buscar.');
        }

        if ($resolved === AdministrativeUserSearchField::Name) {
            $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
            $length = mb_strlen($normalized, 'UTF-8');
            if ($length < 3 || $length > 100) {
                throw new InvalidAdministrativeUserSearch('El nombre requiere entre 3 y 100 caracteres.');
            }
        } elseif ($resolved === AdministrativeUserSearchField::Identification) {
            if (mb_strlen($normalized, 'UTF-8') > 50) {
                throw new InvalidAdministrativeUserSearch('La identificación es demasiado larga.');
            }
        } else {
            try {
                $normalized = (new LoginIdentifier($normalized))->value();
            } catch (InvalidUserState) {
                throw new InvalidAdministrativeUserSearch('El identificador de acceso no es válido.');
            }
        }

        $this->field = $resolved;
        $this->value = $normalized;
    }
}

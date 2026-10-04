<?php

declare(strict_types=1);

namespace App\Person\Application\Discovery;

use App\Person\Application\Discovery\Exception\InvalidPersonDiscoveryCriteria;
use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\ValueObject\ContactInformation;

final readonly class PersonDiscoveryCriteria
{
    public PersonDiscoveryField $field;

    public string $value;

    public function __construct(string $field, string $value)
    {
        $resolvedField = PersonDiscoveryField::tryFrom(trim($field));
        if ($resolvedField === null) {
            throw new InvalidPersonDiscoveryCriteria('Selecciona un criterio de búsqueda válido.');
        }

        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidPersonDiscoveryCriteria('Ingresa un valor para buscar.');
        }

        if ($resolvedField->isName()) {
            $normalized = $this->normalizeWhitespace($normalized);
            if (mb_strlen($normalized, 'UTF-8') < 3) {
                throw new InvalidPersonDiscoveryCriteria('Los nombres y apellidos requieren al menos tres caracteres.');
            }
            if (mb_strlen($normalized, 'UTF-8') > 100) {
                throw new InvalidPersonDiscoveryCriteria('El criterio de nombre o apellido es demasiado largo.');
            }
        } elseif ($resolvedField === PersonDiscoveryField::IdentificationNumber) {
            if (mb_strlen($normalized, 'UTF-8') > 50) {
                throw new InvalidPersonDiscoveryCriteria('El número de identificación es demasiado largo.');
            }
        } else {
            try {
                $normalized = (new ContactInformation($normalized, null, null))->email() ?? '';
            } catch (InvalidPersonState) {
                throw new InvalidPersonDiscoveryCriteria('Ingresa un correo personal válido.');
            }
        }

        $this->field = $resolvedField;
        $this->value = $normalized;
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $value);

        return is_string($normalized) ? $normalized : $value;
    }
}

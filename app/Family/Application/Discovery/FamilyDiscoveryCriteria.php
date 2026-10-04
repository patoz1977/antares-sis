<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery;

use App\Family\Application\Discovery\Exception\InvalidFamilyDiscoveryCriteria;
use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\ValueObject\FamilyCode;
use App\Person\Application\Discovery\Exception\InvalidPersonDiscoveryCriteria;
use App\Person\Application\Discovery\PersonDiscoveryCriteria;

final readonly class FamilyDiscoveryCriteria
{
    public FamilyDiscoveryField $field;

    public string $value;

    public ?PersonDiscoveryCriteria $personCriteria;

    public function __construct(string $field, string $value)
    {
        $resolvedField = FamilyDiscoveryField::tryFrom(trim($field));
        if ($resolvedField === null) {
            throw new InvalidFamilyDiscoveryCriteria('Selecciona un criterio de búsqueda válido.');
        }

        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidFamilyDiscoveryCriteria('Ingresa un valor para buscar.');
        }

        $personField = $resolvedField->personField();
        if ($personField !== null) {
            try {
                $personCriteria = new PersonDiscoveryCriteria($personField->value, $normalized);
            } catch (InvalidPersonDiscoveryCriteria $exception) {
                throw new InvalidFamilyDiscoveryCriteria($exception->getMessage());
            }
            $normalized = $personCriteria->value;
        } elseif ($resolvedField === FamilyDiscoveryField::DisplayName) {
            $normalized = $this->normalizeWhitespace($normalized);
            if (mb_strlen($normalized, 'UTF-8') < 3) {
                throw new InvalidFamilyDiscoveryCriteria('El nombre visible requiere al menos tres caracteres.');
            }
            if (mb_strlen($normalized, 'UTF-8') > 200) {
                throw new InvalidFamilyDiscoveryCriteria('El nombre visible es demasiado largo.');
            }
            $personCriteria = null;
        } else {
            try {
                $normalized = (new FamilyCode($normalized))->value();
            } catch (InvalidFamilyState) {
                throw new InvalidFamilyDiscoveryCriteria('Ingresa un código de familia válido.');
            }
            $personCriteria = null;
        }

        $this->field = $resolvedField;
        $this->value = $normalized;
        $this->personCriteria = $personCriteria ?? null;
    }

    public function isPersonSearch(): bool
    {
        return $this->personCriteria !== null;
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $value);

        return is_string($normalized) ? $normalized : $value;
    }
}

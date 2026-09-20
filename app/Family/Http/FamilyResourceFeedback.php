<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Domain\Exception\InvalidFamilyState;

final class FamilyResourceFeedback
{
    public static function forInvalidState(InvalidFamilyState $error): string
    {
        return match ($error->getCode()) {
            InvalidFamilyState::ASSIGNED_ADDRESS =>
                'No se puede desactivar la dirección mientras tenga asignaciones activas.',
            InvalidFamilyState::ASSIGNED_EMERGENCY_CONTACT =>
                'No se puede desactivar el contacto de emergencia mientras tenga asignaciones activas.',
            InvalidFamilyState::ASSIGNED_AUTHORIZED_PICKUP =>
                'No se puede desactivar la persona autorizada mientras tenga asignaciones activas.',
            default => 'El recurso seleccionado no está disponible para esta familia.',
        };
    }
}

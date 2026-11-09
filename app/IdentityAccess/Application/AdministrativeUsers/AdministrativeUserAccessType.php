<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

enum AdministrativeUserAccessType: string
{
    case Administrator = 'ADMINISTRATOR';
    case Representative = 'REPRESENTATIVE';
    case InstitutionalReporting = 'INSTITUTIONAL_REPORTING';
    case Unsupported = 'UNSUPPORTED';
    case Incoherent = 'INCOHERENT';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Representative => 'Representante',
            self::InstitutionalReporting => 'Reportes institucionales',
            self::Unsupported => 'Sin perfil operativo',
            self::Incoherent => 'Estado incoherente',
        };
    }
}

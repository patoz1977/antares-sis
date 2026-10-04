<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

enum PhysicalDepartureState: string
{
    case NoEnrollment = 'Sin matrícula';
    case MayLeaveAlone = 'Sí puede salir solo';
    case RequiresPickup = 'No puede salir solo';
    case MissingAuthorizedPickup = 'Sin persona autorizada registrada';
}

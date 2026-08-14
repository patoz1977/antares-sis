<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain;

enum AcknowledgementRequirementStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}

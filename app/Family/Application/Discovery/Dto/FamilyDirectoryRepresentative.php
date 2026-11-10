<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery\Dto;

final readonly class FamilyDirectoryRepresentative
{
    public function __construct(
        public string $fullName,
        public ?string $mobilePhone,
        public ?string $personalEmail,
    ) {
    }
}

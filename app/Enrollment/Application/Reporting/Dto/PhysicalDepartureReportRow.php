<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\Enrollment\Application\Reporting\PhysicalDepartureState;
use InvalidArgumentException;

final readonly class PhysicalDepartureReportRow
{
    public PhysicalDepartureState $departureState;

    /** @param list<PhysicalDepartureAuthorizedPickup> $authorizedPickups */
    public function __construct(
        public int $studentId,
        public ?string $gradeName,
        public ?string $sectionName,
        public string $studentFirstName,
        public ?string $studentMiddleName,
        public string $studentFirstSurname,
        public ?string $studentSecondSurname,
        public ?string $studentIdentificationType,
        public ?string $studentIdentificationNumber,
        public ?bool $isAuthorizedToLeaveAlone,
        public array $authorizedPickups,
    ) {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Physical departure report requires a positive Student identity.');
        }
        foreach ($authorizedPickups as $pickup) {
            if (!$pickup instanceof PhysicalDepartureAuthorizedPickup) {
                throw new InvalidArgumentException('Physical departure report contains an invalid pickup projection.');
            }
        }

        $this->departureState = match (true) {
            $isAuthorizedToLeaveAlone === null => PhysicalDepartureState::NoEnrollment,
            $isAuthorizedToLeaveAlone => PhysicalDepartureState::MayLeaveAlone,
            $authorizedPickups !== [] => PhysicalDepartureState::RequiresPickup,
            default => PhysicalDepartureState::MissingAuthorizedPickup,
        };
    }
}

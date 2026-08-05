<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class FamilyOutput
{
    /**
     * @param list<FamilyRepresentativeOutput> $representatives
     * @param list<FamilyStudentOutput> $students
     */
    public function __construct(
        public int $id,
        public string $displayName,
        public FamilyStatus $status,
        public array $representatives,
        public array $students,
    ) {
    }

    public static function fromFamily(Family $family, ?FamilyId $expectedId = null): self
    {
        $id = $family->id();
        if ($id === null || ($expectedId !== null && !$id->equals($expectedId))) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an invalid persisted Family identity.'
            );
        }

        return new self(
            $id->value(),
            $family->displayName()->value(),
            $family->status(),
            array_map(
                static fn (FamilyRepresentative $membership): FamilyRepresentativeOutput =>
                    FamilyRepresentativeOutput::fromMembership($membership),
                $family->representatives(),
            ),
            array_map(
                static fn (FamilyStudent $membership): FamilyStudentOutput =>
                    FamilyStudentOutput::fromMembership($membership),
                $family->students(),
            ),
        );
    }
}

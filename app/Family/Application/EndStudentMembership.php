<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\EndStudentMembershipInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class EndStudentMembership
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(EndStudentMembershipInput $input): FamilyOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = $this->families->findById($familyId);
        if ($family === null) {
            throw new FamilyNotFound('Family was not found.');
        }

        $family->endStudentMembership(new StudentId($input->studentId), $input->endedAt);
        $persisted = $this->families->save($family);

        return FamilyOutput::fromFamily($persisted, $familyId);
    }
}

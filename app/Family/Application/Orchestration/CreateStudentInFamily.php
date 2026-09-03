<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration;

use App\Family\Application\GetFamily;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Application\Orchestration\Dto\StudentFamilyOutput;
use Core\Application\TransactionRunner;
use DateTimeImmutable;

final readonly class CreateStudentInFamily
{
    public function __construct(
        private TransactionRunner $transactions,
        private GetFamily $getFamily,
        private StudentFamilyCoordinator $coordinator,
    ) {
    }

    public function handle(
        CreateStudentInFamilyInput $input,
        DateTimeImmutable $today,
    ): StudentFamilyOutput {
        return $this->transactions->run(function () use ($input, $today): StudentFamilyOutput {
            $this->getFamily->handle($input->familyId);
            return $this->coordinator->createWithNewPerson($input, $today);
        });
    }
}

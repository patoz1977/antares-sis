<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Support;

use App\Enrollment\Application\Dto\AcademicPlacementOutput;
use App\Enrollment\Application\Dto\BillingInformationOutput;
use App\Enrollment\Application\Dto\EnrollmentMutationInput;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\MedicalInformationOutput;
use App\Enrollment\Application\Dto\TransportInformationOutput;
use App\Enrollment\Application\Exception\EnrollmentContextMismatch;
use App\Enrollment\Application\Exception\EnrollmentNotFound;
use App\Enrollment\Application\Exception\EnrollmentPersistedStateMismatch;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use Core\Application\TransactionRunner;
use DateTimeImmutable;
use DateTimeZone;

final class EnrollmentApplicationSupport
{
    /** @param callable(Enrollment): void $mutation */
    public static function update(
        EnrollmentRepository $enrollments,
        TransactionRunner $transactions,
        EnrollmentMutationInput $input,
        callable $mutation,
    ): EnrollmentOutput {
        return $transactions->run(function () use ($enrollments, $input, $mutation): EnrollmentOutput {
            $enrollment = $enrollments->findByIdForUpdate(new EnrollmentId($input->enrollmentId));
            if ($enrollment === null) {
                throw new EnrollmentNotFound('Enrollment was not found.');
            }
            self::assertContext($enrollment, $input);
            $mutation($enrollment);

            return self::save($enrollments, $enrollment);
        });
    }

    public static function save(
        EnrollmentRepository $enrollments,
        Enrollment $expected,
    ): EnrollmentOutput {
        $persisted = $enrollments->save($expected);
        if (!self::sameState($persisted, $expected)) {
            throw new EnrollmentPersistedStateMismatch(
                'Enrollment persistence returned incoherent state.'
            );
        }

        return self::output($persisted);
    }

    public static function output(Enrollment $enrollment): EnrollmentOutput
    {
        $id = $enrollment->id();
        if ($id === null || $id->value() <= 0) {
            throw new EnrollmentPersistedStateMismatch(
                'Enrollment does not have a positive persisted identity.'
            );
        }

        $placement = $enrollment->academicPlacement();
        $billing = $enrollment->billingInformation();
        $medical = $enrollment->medicalInformation();
        $transport = $enrollment->transportInformation();

        return new EnrollmentOutput(
            $id->value(),
            $enrollment->studentId()->value(),
            $enrollment->familyId()->value(),
            $enrollment->academicPeriodId()->value(),
            $enrollment->status()->value,
            $placement === null ? null : new AcademicPlacementOutput(
                $placement->gradeId()->value(),
                $placement->sectionId()?->value(),
            ),
            $billing === null ? null : new BillingInformationOutput(
                $billing->identificationTypeId()->value(),
                $billing->identificationNumber(),
                $billing->legalName(),
                $billing->billingAddress(),
                $billing->billingEmail(),
                $billing->phone(),
            ),
            $medical === null ? null : new MedicalInformationOutput(...self::medicalState($medical)),
            $transport === null ? null : new TransportInformationOutput(
                $transport->requiresInstitutionalTransport(),
            ),
            $enrollment->isAuthorizedToLeaveAlone(),
            self::utcSecond($enrollment->startedAt()),
            self::nullableUtcSecond($enrollment->submittedAt()),
            self::nullableUtcSecond($enrollment->completedAt()),
            self::nullableUtcSecond($enrollment->cancelledAt()),
            $enrollment->submissionSnapshot() !== null,
        );
    }

    private static function assertContext(
        Enrollment $enrollment,
        EnrollmentMutationInput $input,
    ): void {
        if ($enrollment->studentId()->value() !== $input->expectedStudentId
            || $enrollment->familyId()->value() !== $input->expectedFamilyId
            || $enrollment->academicPeriodId()->value() !== $input->expectedAcademicPeriodId
        ) {
            throw new EnrollmentContextMismatch('Enrollment is unavailable in the expected context.');
        }
    }

    private static function sameState(Enrollment $persisted, Enrollment $expected): bool
    {
        $persistedId = $persisted->id();
        $expectedId = $expected->id();

        return $persistedId !== null
            && ($expectedId === null || $persistedId->equals($expectedId))
            && $persisted->studentId()->equals($expected->studentId())
            && $persisted->familyId()->equals($expected->familyId())
            && $persisted->academicPeriodId()->equals($expected->academicPeriodId())
            && $persisted->status() === $expected->status()
            && self::optionalEquals($persisted->academicPlacement(), $expected->academicPlacement())
            && self::optionalEquals($persisted->billingInformation(), $expected->billingInformation())
            && self::sameMedical($persisted->medicalInformation(), $expected->medicalInformation())
            && self::sameTransport($persisted, $expected)
            && $persisted->isAuthorizedToLeaveAlone() === $expected->isAuthorizedToLeaveAlone()
            && self::sameTimestamp($persisted->startedAt(), $expected->startedAt())
            && self::sameNullableTimestamp($persisted->submittedAt(), $expected->submittedAt())
            && self::sameNullableTimestamp($persisted->completedAt(), $expected->completedAt())
            && self::sameNullableTimestamp($persisted->cancelledAt(), $expected->cancelledAt())
            && ($persisted->submissionSnapshot() === null) === ($expected->submissionSnapshot() === null);
    }

    private static function optionalEquals(?object $left, ?object $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private static function sameMedical(
        ?MedicalInformation $left,
        ?MedicalInformation $right,
    ): bool {
        return $left === null
            ? $right === null
            : $right !== null && self::medicalState($left) === self::medicalState($right);
    }

    /** @return array{bool, ?string, bool, ?string, bool, ?string, bool, ?string, bool, ?string, ?string, ?string, ?string} */
    private static function medicalState(MedicalInformation $medical): array
    {
        return [
            $medical->hasMedicalCondition(),
            $medical->medicalConditionDetail(),
            $medical->hasAllergies(),
            $medical->allergyDetail(),
            $medical->takesPermanentMedication(),
            $medical->medicationName(),
            $medical->requiresSpecialCare(),
            $medical->specialCareDetail(),
            $medical->hasMedicalInsurance(),
            $medical->insuranceProvider(),
            $medical->pediatricianName(),
            $medical->pediatricianPhone(),
            $medical->observations(),
        ];
    }

    private static function sameTransport(Enrollment $left, Enrollment $right): bool
    {
        $leftTransport = $left->transportInformation();
        $rightTransport = $right->transportInformation();

        return $leftTransport === null
            ? $rightTransport === null
            : $rightTransport !== null
                && $leftTransport->requiresInstitutionalTransport()
                    === $rightTransport->requiresInstitutionalTransport();
    }

    private static function sameTimestamp(DateTimeImmutable $left, DateTimeImmutable $right): bool
    {
        return self::timestamp($left) === self::timestamp($right);
    }

    private static function sameNullableTimestamp(
        ?DateTimeImmutable $left,
        ?DateTimeImmutable $right,
    ): bool {
        return $left === null ? $right === null : $right !== null && self::sameTimestamp($left, $right);
    }

    private static function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function utcSecond(DateTimeImmutable $value): DateTimeImmutable
    {
        $utc = $value->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
        );
    }

    private static function nullableUtcSecond(?DateTimeImmutable $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::utcSecond($value);
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Enrollment\Domain\ValueObject\TransportInformation;
use DateTimeImmutable;

final class Enrollment
{
    private function __construct(
        private readonly ?EnrollmentId $id,
        private readonly StudentId $studentId,
        private readonly FamilyId $familyId,
        private readonly AcademicPeriodId $academicPeriodId,
        private EnrollmentStatus $status,
        private ?AcademicPlacement $academicPlacement,
        private ?BillingInformation $billingInformation,
        private ?MedicalInformation $medicalInformation,
        private ?TransportInformation $transportInformation,
        private bool $isAuthorizedToLeaveAlone,
        private ?EnrollmentSubmissionSnapshot $submissionSnapshot,
        private readonly DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $submittedAt,
        private ?DateTimeImmutable $completedAt,
        private ?DateTimeImmutable $cancelledAt,
    ) {
    }

    public static function startDraft(
        StudentId $studentId,
        FamilyId $familyId,
        AcademicPeriodId $academicPeriodId,
        DateTimeImmutable $startedAt,
        ?AcademicPlacement $academicPlacement = null,
        ?BillingInformation $billingInformation = null,
        ?MedicalInformation $medicalInformation = null,
        ?TransportInformation $transportInformation = null,
        bool $isAuthorizedToLeaveAlone = false,
    ): self {
        return new self(
            null,
            $studentId,
            $familyId,
            $academicPeriodId,
            EnrollmentStatus::Draft,
            $academicPlacement,
            $billingInformation,
            $medicalInformation,
            $transportInformation,
            $isAuthorizedToLeaveAlone,
            null,
            $startedAt,
            null,
            null,
            null,
        );
    }

    public static function reconstitute(
        EnrollmentId $id,
        StudentId $studentId,
        FamilyId $familyId,
        AcademicPeriodId $academicPeriodId,
        EnrollmentStatus $status,
        ?AcademicPlacement $academicPlacement,
        ?BillingInformation $billingInformation,
        ?MedicalInformation $medicalInformation,
        ?TransportInformation $transportInformation,
        bool $isAuthorizedToLeaveAlone,
        ?EnrollmentSubmissionSnapshot $submissionSnapshot,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $submittedAt,
        ?DateTimeImmutable $completedAt,
        ?DateTimeImmutable $cancelledAt,
    ): self {
        self::assertPersistedState(
            $status,
            $submissionSnapshot,
            $startedAt,
            $submittedAt,
            $completedAt,
            $cancelledAt,
        );

        if ($submissionSnapshot !== null && $submissionSnapshot->id() === null) {
            throw new InvalidEnrollmentState('A reconstituted Enrollment requires a persisted snapshot identity.');
        }

        return new self(
            $id,
            $studentId,
            $familyId,
            $academicPeriodId,
            $status,
            $academicPlacement,
            $billingInformation,
            $medicalInformation,
            $transportInformation,
            $isAuthorizedToLeaveAlone,
            $submissionSnapshot,
            $startedAt,
            $submittedAt,
            $completedAt,
            $cancelledAt,
        );
    }

    public function id(): ?EnrollmentId
    {
        return $this->id;
    }

    public function studentId(): StudentId
    {
        return $this->studentId;
    }

    public function familyId(): FamilyId
    {
        return $this->familyId;
    }

    public function academicPeriodId(): AcademicPeriodId
    {
        return $this->academicPeriodId;
    }

    public function status(): EnrollmentStatus
    {
        return $this->status;
    }

    public function academicPlacement(): ?AcademicPlacement
    {
        return $this->academicPlacement;
    }

    public function billingInformation(): ?BillingInformation
    {
        return $this->billingInformation;
    }

    public function medicalInformation(): ?MedicalInformation
    {
        return $this->medicalInformation;
    }

    public function transportInformation(): ?TransportInformation
    {
        return $this->transportInformation;
    }

    public function isAuthorizedToLeaveAlone(): bool
    {
        return $this->isAuthorizedToLeaveAlone;
    }

    public function submissionSnapshot(): ?EnrollmentSubmissionSnapshot
    {
        return $this->submissionSnapshot;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function submittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function updateAcademicPlacement(AcademicPlacement $academicPlacement): void
    {
        $this->assertDraft();
        $this->academicPlacement = $academicPlacement;
    }

    public function updateBillingInformation(BillingInformation $billingInformation): void
    {
        $this->assertDraft();
        $this->billingInformation = $billingInformation;
    }

    public function updateMedicalInformation(MedicalInformation $medicalInformation): void
    {
        $this->assertDraft();
        $this->medicalInformation = $medicalInformation;
    }

    public function updateTransportInformation(TransportInformation $transportInformation): void
    {
        $this->assertDraft();
        $this->transportInformation = $transportInformation;
    }

    public function updateLeaveAloneAuthorization(bool $isAuthorized): void
    {
        $this->assertDraft();
        $this->isAuthorizedToLeaveAlone = $isAuthorized;
    }

    public function submit(
        EnrollmentSubmissionSnapshot $submissionSnapshot,
        DateTimeImmutable $submittedAt,
    ): void {
        $this->assertDraft();
        $this->assertNotBeforeStartedAt($submittedAt, 'SubmittedAt');
        if ($this->submittedAt !== null && $submittedAt < $this->submittedAt) {
            throw new InvalidEnrollmentState('A resubmission cannot precede the previous submission.');
        }

        $this->submissionSnapshot = $submissionSnapshot;
        $this->submittedAt = $submittedAt;
        $this->completedAt = null;
        $this->cancelledAt = null;
        $this->status = EnrollmentStatus::Submitted;
    }

    public function reopen(): void
    {
        if ($this->status !== EnrollmentStatus::Submitted) {
            throw new InvalidEnrollmentState('Only a Submitted Enrollment can be reopened.');
        }

        $this->status = EnrollmentStatus::Draft;
    }

    public function complete(DateTimeImmutable $completedAt): void
    {
        if ($this->status !== EnrollmentStatus::Submitted) {
            throw new InvalidEnrollmentState('Only a Submitted Enrollment can be completed.');
        }
        $this->assertNotBeforeStartedAt($completedAt, 'CompletedAt');
        if ($this->submittedAt === null || $completedAt < $this->submittedAt) {
            throw new InvalidEnrollmentState('CompletedAt cannot precede SubmittedAt.');
        }

        $this->completedAt = $completedAt;
        $this->status = EnrollmentStatus::Completed;
    }

    public function cancel(DateTimeImmutable $cancelledAt): void
    {
        if (!in_array($this->status, [EnrollmentStatus::Draft, EnrollmentStatus::Submitted], true)) {
            throw new InvalidEnrollmentState('Only a Draft or Submitted Enrollment can be cancelled.');
        }
        $this->assertNotBeforeStartedAt($cancelledAt, 'CancelledAt');
        if ($this->submittedAt !== null && $cancelledAt < $this->submittedAt) {
            throw new InvalidEnrollmentState('CancelledAt cannot precede SubmittedAt.');
        }

        $this->cancelledAt = $cancelledAt;
        $this->status = EnrollmentStatus::Cancelled;
    }

    private function assertDraft(): void
    {
        if ($this->status !== EnrollmentStatus::Draft) {
            throw new InvalidEnrollmentState('Enrollment annual information can only change while Draft.');
        }
    }

    private function assertNotBeforeStartedAt(DateTimeImmutable $timestamp, string $label): void
    {
        if ($timestamp < $this->startedAt) {
            throw new InvalidEnrollmentState(sprintf('%s cannot precede StartedAt.', $label));
        }
    }

    private static function assertPersistedState(
        EnrollmentStatus $status,
        ?EnrollmentSubmissionSnapshot $snapshot,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $submittedAt,
        ?DateTimeImmutable $completedAt,
        ?DateTimeImmutable $cancelledAt,
    ): void {
        foreach (['SubmittedAt' => $submittedAt, 'CompletedAt' => $completedAt, 'CancelledAt' => $cancelledAt] as $label => $timestamp) {
            if ($timestamp !== null && $timestamp < $startedAt) {
                throw new InvalidEnrollmentState(sprintf('%s cannot precede StartedAt.', $label));
            }
        }

        $hasSubmissionHistory = $submittedAt !== null && $snapshot !== null;
        if (($submittedAt === null) !== ($snapshot === null)) {
            throw new InvalidEnrollmentState('SubmittedAt and the submission snapshot must be present together.');
        }

        if ($status === EnrollmentStatus::Draft
            && ($completedAt !== null || $cancelledAt !== null)) {
            throw new InvalidEnrollmentState('A Draft Enrollment cannot have completion or cancellation timestamps.');
        }
        if ($status === EnrollmentStatus::Submitted
            && (!$hasSubmissionHistory || $completedAt !== null || $cancelledAt !== null)) {
            throw new InvalidEnrollmentState('A Submitted Enrollment requires only submission history.');
        }
        if ($status === EnrollmentStatus::Completed
            && (!$hasSubmissionHistory || $completedAt === null || $cancelledAt !== null)) {
            throw new InvalidEnrollmentState('A Completed Enrollment requires submission and completion history.');
        }
        if ($status === EnrollmentStatus::Cancelled
            && ($cancelledAt === null || $completedAt !== null)) {
            throw new InvalidEnrollmentState('A Cancelled Enrollment requires cancellation history and cannot be completed.');
        }

        if ($submittedAt !== null && $completedAt !== null && $completedAt < $submittedAt) {
            throw new InvalidEnrollmentState('CompletedAt cannot precede SubmittedAt.');
        }
        if ($submittedAt !== null && $cancelledAt !== null && $cancelledAt < $submittedAt) {
            throw new InvalidEnrollmentState('CancelledAt cannot precede SubmittedAt.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\ValueObject;

use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\Support\EnrollmentText;

final readonly class MedicalInformation
{
    private ?string $medicalConditionDetail;

    private ?string $allergyDetail;

    private ?string $medicationName;

    private ?string $specialCareDetail;

    private ?string $insuranceProvider;

    private ?string $pediatricianName;

    private ?string $pediatricianPhone;

    private ?string $observations;

    public function __construct(
        private bool $hasMedicalCondition,
        ?string $medicalConditionDetail,
        private bool $hasAllergies,
        ?string $allergyDetail,
        private bool $takesPermanentMedication,
        ?string $medicationName,
        private bool $requiresSpecialCare,
        ?string $specialCareDetail,
        private bool $hasMedicalInsurance,
        ?string $insuranceProvider,
        ?string $pediatricianName,
        ?string $pediatricianPhone,
        ?string $observations,
    ) {
        $this->medicalConditionDetail = EnrollmentText::optional(
            $medicalConditionDetail,
            500,
            'Medical condition detail',
        );
        $this->allergyDetail = EnrollmentText::optional($allergyDetail, 500, 'Allergy detail');
        $this->medicationName = EnrollmentText::optional($medicationName, 255, 'Medication name');
        $this->specialCareDetail = EnrollmentText::optional($specialCareDetail, 500, 'Special care detail');
        $this->insuranceProvider = EnrollmentText::optional($insuranceProvider, 255, 'Insurance provider');
        $this->pediatricianName = EnrollmentText::optional($pediatricianName, 200, 'Pediatrician name');
        $this->pediatricianPhone = EnrollmentText::optional($pediatricianPhone, 30, 'Pediatrician phone');
        $this->observations = EnrollmentText::optional($observations, null, 'Medical observations');

        $this->requireDetail($hasMedicalCondition, $this->medicalConditionDetail, 'Medical condition detail');
        $this->requireDetail($hasAllergies, $this->allergyDetail, 'Allergy detail');
        $this->requireDetail($takesPermanentMedication, $this->medicationName, 'Medication name');
        $this->requireDetail($requiresSpecialCare, $this->specialCareDetail, 'Special care detail');
        $this->requireDetail($hasMedicalInsurance, $this->insuranceProvider, 'Insurance provider');
    }

    public function hasMedicalCondition(): bool
    {
        return $this->hasMedicalCondition;
    }

    public function medicalConditionDetail(): ?string
    {
        return $this->medicalConditionDetail;
    }

    public function hasAllergies(): bool
    {
        return $this->hasAllergies;
    }

    public function allergyDetail(): ?string
    {
        return $this->allergyDetail;
    }

    public function takesPermanentMedication(): bool
    {
        return $this->takesPermanentMedication;
    }

    public function medicationName(): ?string
    {
        return $this->medicationName;
    }

    public function requiresSpecialCare(): bool
    {
        return $this->requiresSpecialCare;
    }

    public function specialCareDetail(): ?string
    {
        return $this->specialCareDetail;
    }

    public function hasMedicalInsurance(): bool
    {
        return $this->hasMedicalInsurance;
    }

    public function insuranceProvider(): ?string
    {
        return $this->insuranceProvider;
    }

    public function pediatricianName(): ?string
    {
        return $this->pediatricianName;
    }

    public function pediatricianPhone(): ?string
    {
        return $this->pediatricianPhone;
    }

    public function observations(): ?string
    {
        return $this->observations;
    }

    private function requireDetail(bool $required, ?string $detail, string $label): void
    {
        if ($required && $detail === null) {
            throw new InvalidEnrollmentState(sprintf('%s is required when its answer is true.', $label));
        }
    }
}

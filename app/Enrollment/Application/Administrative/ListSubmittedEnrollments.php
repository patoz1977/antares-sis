<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative;

use App\Enrollment\Application\Administrative\Dto\SubmittedEnrollmentListItem;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentNotSubmitted;
use App\Person\Application\Dto\PersonOutput;

final readonly class ListSubmittedEnrollments
{
    public function __construct(
        private SubmittedEnrollmentIdQuery $submittedEnrollmentIds,
        private GetAdministrativeEnrollmentReviewContext $getReviewContext,
    ) {
    }

    /** @return list<SubmittedEnrollmentListItem> */
    public function handle(): array
    {
        $items = [];
        foreach ($this->submittedEnrollmentIds->findSubmittedEnrollmentIds() as $enrollmentId) {
            try {
                $context = $this->getReviewContext->handle($enrollmentId);
            } catch (AdministrativeEnrollmentNotSubmitted) {
                continue;
            }

            $submittedAt = $context->enrollment->submittedAt;
            if ($submittedAt === null) {
                throw new \RuntimeException('Submitted Enrollment is missing submitted_at.');
            }

            $items[] = new SubmittedEnrollmentListItem(
                $context->enrollment->id,
                $this->displayName($context->studentPerson),
                $context->familyDisplayName,
                $context->academicPeriod->name,
                $context->grade?->name,
                $submittedAt,
            );
        }

        return $items;
    }

    private function displayName(PersonOutput $person): string
    {
        return implode(' ', array_filter([
            $person->firstName,
            $person->middleName,
            $person->firstSurname,
            $person->secondSurname,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}

<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission\Dto;

use InvalidArgumentException;

final readonly class EnrollmentSubmissionValidationResult
{
    public bool $isSubmittable;

    /** @param list<EnrollmentSubmissionRequirement> $requirements */
    public function __construct(public array $requirements)
    {
        $codes = [];
        $submittable = true;
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof EnrollmentSubmissionRequirement
                || isset($codes[$requirement->code])
            ) {
                throw new InvalidArgumentException('Submission requirements must have unique stable codes.');
            }
            $codes[$requirement->code] = true;
            $submittable = $submittable && $requirement->satisfied;
        }

        $this->isSubmittable = $submittable;
    }
}

<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission\Exception;

use App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionValidationResult;
use RuntimeException;

final class EnrollmentSubmissionNotReady extends RuntimeException
{
    public function __construct(
        public readonly EnrollmentSubmissionValidationResult $validation,
    ) {
        parent::__construct('Enrollment does not satisfy the current Submission requirements.');
    }
}

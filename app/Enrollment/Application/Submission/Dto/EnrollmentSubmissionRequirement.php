<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission\Dto;

use InvalidArgumentException;

final readonly class EnrollmentSubmissionRequirement
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $satisfied,
    ) {
        if (trim($code) === '' || trim($message) === '') {
            throw new InvalidArgumentException('Submission requirement code and message are required.');
        }
    }
}

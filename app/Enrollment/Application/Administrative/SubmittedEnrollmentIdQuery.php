<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative;

interface SubmittedEnrollmentIdQuery
{
    /** @return list<int> */
    public function findSubmittedEnrollmentIds(): array;
}
